<?php
declare(strict_types=1);

const AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_SETTING = 'avatar_relationship_max_regular_links';
const AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_REVISION_SETTING = 'avatar_relationship_max_regular_links_revision';
const AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_DEFAULT = 8;
const AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MIN = 2;
const AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MAX = 16;
const AVATAR_RELATIONSHIP_COMPLETE_UNIT_MAX_PX = 2048;
const AVATAR_RELATIONSHIP_PREFERRED_COMPACT_EXTENT_PX = 8384;
const AVATAR_RELATIONSHIP_HARD_EXTENT_MAX_PX = 33728;

function avatar_relationship_capacity_setting_defaults(): array {
    return [
        AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_SETTING => (string)AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_DEFAULT,
        AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_REVISION_SETTING => '1',
    ];
}

function avatar_relationship_capacity_policy(PDO $pdo): array {
    $stored = filter_var(
        app_setting(
            $pdo,
            AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_SETTING,
            (string)AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_DEFAULT
        ),
        FILTER_VALIDATE_INT
    );
    $limit = $stored === false
        ? AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_DEFAULT
        : max(
            AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MIN,
            min(AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MAX, (int)$stored)
        );
    return [
        'settingKey' => AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_SETTING,
        'revision' => max(1, (int)app_setting(
            $pdo,
            AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_REVISION_SETTING,
            '1'
        )),
        'maximumRegularAvatarLinks' => $limit,
        'defaultMaximumRegularAvatarLinks' => AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_DEFAULT,
        'minimumRegularAvatarLinks' => AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MIN,
        'maximumConfigurableRegularAvatarLinks' => AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MAX,
        'hardMaximumRegularAvatarLinks' => AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MAX,
        'completeUnitMaximumWidthPx' => AVATAR_RELATIONSHIP_COMPLETE_UNIT_MAX_PX,
        'completeUnitMaximumHeightPx' => AVATAR_RELATIONSHIP_COMPLETE_UNIT_MAX_PX,
        'preferredCompactMaximumWidthPx' => AVATAR_RELATIONSHIP_PREFERRED_COMPACT_EXTENT_PX,
        'preferredCompactMaximumHeightPx' => AVATAR_RELATIONSHIP_PREFERRED_COMPACT_EXTENT_PX,
        'hardRelationshipExtentMaximumPx' => AVATAR_RELATIONSHIP_HARD_EXTENT_MAX_PX,
        'label' => 'Maximum regular avatar links in one relationship',
        'description' => 'Controls how many regularly linked avatars can belong to one relationship. Left and right lap links do not count toward this limit because they remain attached to an existing regular avatar link.',
    ];
}

function avatar_relationship_capacity_validate_setting(mixed $value): array {
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false
        || (int)$parsed < AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MIN
        || (int)$parsed > AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MAX) {
        return [
            'ok' => false,
            'code' => 'RELATIONSHIP_CAPACITY_SETTING_INVALID',
            'error' => 'Maximum regular avatar links in one relationship must be a whole number from 2 to 16.',
            'http_status' => 400,
        ];
    }
    return ['ok' => true, 'value' => (int)$parsed];
}

function avatar_relationship_capacity_relationships_above_limit(PDO $pdo, int $limit): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM (
             SELECT ar.id
               FROM avatar_relationships ar
               JOIN avatar_relationship_members arm ON arm.relationship_id = ar.id
              WHERE ar.status = 'active'
                AND arm.membership_status = 'active'
                AND arm.relationship_role = 'normal'
              GROUP BY ar.id
             HAVING COUNT(*) > CAST(? AS INTEGER)
           ) relationship_capacity_over_limit"
    );
    $stmt->execute([$limit]);
    return (int)$stmt->fetchColumn();
}

function avatar_relationship_capacity_impact(PDO $pdo, mixed $value): array {
    $validation = avatar_relationship_capacity_validate_setting($value);
    if (empty($validation['ok'])) return $validation;
    $policy = avatar_relationship_capacity_policy($pdo);
    $next = (int)$validation['value'];
    $current = (int)$policy['maximumRegularAvatarLinks'];
    return [
        'ok' => true,
        'currentMaximumRegularAvatarLinks' => $current,
        'proposedMaximumRegularAvatarLinks' => $next,
        'isLowering' => $next < $current,
        'relationshipsAboveProposedLimit' => $next < $current
            ? avatar_relationship_capacity_relationships_above_limit($pdo, $next)
            : 0,
        'revision' => (int)$policy['revision'],
    ];
}

function avatar_relationship_capacity_update(
    PDO $pdo,
    mixed $value,
    mixed $expectedRevision,
    bool $confirmed,
    int $actorUserId,
    string $source = 'admin'
): array {
    $validation = avatar_relationship_capacity_validate_setting($value);
    if (empty($validation['ok'])) return $validation;
    $revision = filter_var($expectedRevision, FILTER_VALIDATE_INT);
    if ($revision === false || (int)$revision < 1) {
        return [
            'ok' => false,
            'code' => 'RELATIONSHIP_CAPACITY_SETTING_REVISION_REQUIRED',
            'error' => 'A current relationship-capacity setting revision is required.',
            'http_status' => 400,
        ];
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $lockSql = 'SELECT setting_key, value FROM app_settings WHERE setting_key IN (?,?) ORDER BY setting_key';
        if (db_uses_mysql_syntax($pdo)) $lockSql .= ' FOR UPDATE';
        $lock = $pdo->prepare($lockSql);
        $lock->execute([
            AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_SETTING,
            AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_REVISION_SETTING,
        ]);
        $lock->fetchAll();

        $before = avatar_relationship_capacity_policy($pdo);
        $current = (int)$before['maximumRegularAvatarLinks'];
        $next = (int)$validation['value'];
        if ($next === $current) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
            return [
                'ok' => true,
                'idempotent' => true,
                'policy' => $before,
                'relationshipsAboveNewLimit' => 0,
            ];
        }
        if ((int)$revision !== (int)$before['revision']) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'RELATIONSHIP_CAPACITY_SETTING_STALE',
                'error' => 'The relationship-capacity setting changed. Refresh and try again.',
                'revision' => (int)$before['revision'],
                'http_status' => 409,
            ];
        }

        $aboveLimit = $next < $current
            ? avatar_relationship_capacity_relationships_above_limit($pdo, $next)
            : 0;
        if ($aboveLimit > 0 && !$confirmed) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'RELATIONSHIP_CAPACITY_CONFIRMATION_REQUIRED',
                'error' => "{$aboveLimit} existing relationship" . ($aboveLimit === 1 ? '' : 's')
                    . ' would remain above the new limit. Confirm this change to continue.',
                'relationshipsAboveProposedLimit' => $aboveLimit,
                'revision' => (int)$before['revision'],
                'http_status' => 409,
            ];
        }

        $nextRevision = (int)$before['revision'] + 1;
        set_app_setting($pdo, AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_SETTING, (string)$next);
        set_app_setting($pdo, AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_REVISION_SETTING, (string)$nextRevision);
        $after = avatar_relationship_capacity_policy($pdo);
        log_tool(
            $pdo,
            $actorUserId > 0 ? $actorUserId : null,
            $source === 'setup' ? 'setup_relationship_capacity_update' : 'admin_relationship_capacity_update',
            null,
            null,
            "Changed maximum regular avatar links in one relationship from {$current} to {$next}; "
                . "{$aboveLimit} existing relationship" . ($aboveLimit === 1 ? '' : 's')
                . ' remain above the new limit.'
        );
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
        return [
            'ok' => true,
            'idempotent' => false,
            'policy' => $after,
            'relationshipsAboveNewLimit' => $aboveLimit,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function avatar_relationship_capacity_regular_member_count(array $members): int {
    return count(array_filter($members, static fn(array $member): bool =>
        (string)($member['membership_status'] ?? 'active') === 'active'
        && (string)($member['relationship_role'] ?? 'normal') === 'normal'
    ));
}

function avatar_relationship_capacity_limit_error(int $limit): array {
    return avatar_relationship_operation_error(
        'RELATIONSHIP_REGULAR_LINK_LIMIT_REACHED',
        "This relationship already has the community maximum of {$limit} regular avatar links.",
        'relationship-regular-link-limit-reached',
        409
    ) + ['maximum_regular_avatar_links' => $limit];
}

function avatar_relationship_capacity_geometry_error(array $projection = []): array {
    return avatar_relationship_operation_error(
        'RELATIONSHIP_GEOMETRY_SAFETY_LIMIT',
        'That relationship layout exceeds the supported geometry safety limit.',
        'relationship-geometry-safety-limit',
        409
    ) + [
        'geometry_safety' => [
            'complete_unit_max_px' => AVATAR_RELATIONSHIP_COMPLETE_UNIT_MAX_PX,
            'relationship_extent_max_px' => AVATAR_RELATIONSHIP_HARD_EXTENT_MAX_PX,
            'projected_width_px' => isset($projection['width']) ? (int)ceil((float)$projection['width']) : null,
            'projected_height_px' => isset($projection['height']) ? (int)ceil((float)$projection['height']) : null,
        ],
    ];
}

function avatar_relationship_capacity_rendered_dimensions(PDO $pdo, array $participant, bool $lap): array {
    $preferences = avatar_size_preferences_public($pdo, $participant);
    $lapMaxEdge = max(1, (int)round(min(150, (int)$preferences['effectiveAvatarDisplayMaxPx']) * 0.5));
    if (!empty($participant['webcam_enabled'])) {
        $width = max(1, (int)$preferences['effectiveWebcamDisplayWidthPx']);
        $height = max(1, (int)$preferences['effectiveWebcamDisplayHeightPx']);
        if ($lap) {
            $scale = min(1, $lapMaxEdge / max($width, $height, 1));
            $width = max(1, (int)round($width * $scale));
            $height = max(1, (int)round($height * $scale));
        }
        return ['width' => $width, 'height' => $height];
    }

    $sourceWidth = max(1, (int)($participant['avatar_source_width_px'] ?? 150));
    $sourceHeight = max(1, (int)($participant['avatar_source_height_px'] ?? 150));
    $maxEdge = max(1, (int)$preferences['effectiveAvatarDisplayMaxPx']);
    if ($lap) $maxEdge = min($maxEdge, $lapMaxEdge);
    $scale = min(1, $maxEdge / max($sourceWidth, $sourceHeight, 1));
    return [
        'width' => max(1, (int)round($sourceWidth * $scale)),
        'height' => max(1, (int)round($sourceHeight * $scale)),
    ];
}

function avatar_relationship_capacity_anchor(array $relationship, array $member): array {
    $anchor = !empty($member['anchor_json'])
        ? (json_decode((string)$member['anchor_json'], true) ?: [])
        : [];
    if ($anchor) return $anchor;
    $metadata = !empty($relationship['metadata_json'])
        ? (json_decode((string)$relationship['metadata_json'], true) ?: [])
        : [];
    $participantId = (string)(int)($member['participant_id'] ?? 0);
    return is_array($metadata['anchors']['members'][$participantId] ?? null)
        ? $metadata['anchors']['members'][$participantId]
        : [];
}

function avatar_relationship_capacity_complete_units(
    PDO $pdo,
    array $relationship,
    array $members,
    array $participantOverrides = []
): array {
    $participantIds = array_values(array_unique(array_map(
        static fn(array $member): int => (int)($member['participant_id'] ?? 0),
        $members
    )));
    $participants = [];
    if ($participantIds) {
        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM participants WHERE id IN ({$placeholders})");
        $stmt->execute($participantIds);
        foreach ($stmt->fetchAll() as $participant) {
            $participants[(int)$participant['id']] = $participant;
        }
    }
    foreach ($participantOverrides as $participant) {
        if ((int)($participant['id'] ?? 0) > 0) $participants[(int)$participant['id']] = $participant;
    }

    $normalMembers = array_values(array_filter($members, static fn(array $member): bool =>
        (string)($member['membership_status'] ?? 'active') === 'active'
        && (string)($member['relationship_role'] ?? 'normal') === 'normal'
    ));
    usort($normalMembers, static fn(array $first, array $second): int =>
        [(int)($first['member_order'] ?? 0), (int)($first['participant_id'] ?? 0)]
        <=> [(int)($second['member_order'] ?? 0), (int)($second['participant_id'] ?? 0)]
    );
    $lapMembers = array_values(array_filter($members, static fn(array $member): bool =>
        (string)($member['membership_status'] ?? 'active') === 'active'
        && (string)($member['relationship_role'] ?? '') === 'lap'
    ));
    $units = [];
    foreach ($normalMembers as $hostMember) {
        $hostId = (int)$hostMember['participant_id'];
        if (empty($participants[$hostId])) return [];
        $host = avatar_relationship_capacity_rendered_dimensions($pdo, $participants[$hostId], false);
        $left = 0.0;
        $top = 0.0;
        $right = (float)$host['width'];
        $bottom = (float)$host['height'];
        foreach ($lapMembers as $lapMember) {
            if ((int)($lapMember['lap_host_participant_id'] ?? 0) !== $hostId) continue;
            $lapId = (int)$lapMember['participant_id'];
            if (empty($participants[$lapId])) return [];
            $lap = avatar_relationship_capacity_rendered_dimensions($pdo, $participants[$lapId], true);
            $anchor = avatar_relationship_capacity_anchor($relationship, $lapMember);
            $normalized = is_array($anchor['normalizedOffset'] ?? null)
                ? $anchor['normalizedOffset']
                : (is_array($anchor['offset'] ?? null) ? $anchor['offset'] : []);
            $pixels = is_array($anchor['pixelOffset'] ?? null) ? $anchor['pixelOffset'] : [];
            $xRatio = is_numeric($normalized['x'] ?? null) ? (float)$normalized['x'] : 0.5;
            $yRatio = is_numeric($normalized['y'] ?? null) ? (float)$normalized['y'] : (65 / 150);
            $pixelX = is_numeric($pixels['x'] ?? null) ? (float)$pixels['x'] : 0.0;
            $pixelY = is_numeric($pixels['y'] ?? null) ? (float)$pixels['y'] : 0.0;
            $side = (string)($lapMember['lap_side'] ?? 'bottom-right');
            $lapX = $side === 'bottom-left'
                ? $host['width'] * (1 - $xRatio) - $lap['width'] + $pixelX
                : $host['width'] * $xRatio + $pixelX;
            $lapY = $host['height'] * $yRatio + $pixelY;
            $left = min($left, $lapX);
            $top = min($top, $lapY);
            $right = max($right, $lapX + $lap['width']);
            $bottom = max($bottom, $lapY + $lap['height']);
        }
        $units[] = [
            'participant_id' => $hostId,
            'width' => $right - $left,
            'height' => $bottom - $top,
        ];
    }
    return $units;
}

function avatar_relationship_capacity_geometry_projection(
    PDO $pdo,
    array $relationship,
    array $members,
    array $participantOverrides = []
): array {
    $units = avatar_relationship_capacity_complete_units($pdo, $relationship, $members, $participantOverrides);
    if (!$units && avatar_relationship_capacity_regular_member_count($members) > 0) {
        return ['ok' => false, 'width' => null, 'height' => null, 'reason' => 'participant-geometry-unavailable'];
    }
    foreach ($units as $unit) {
        if ((float)$unit['width'] > AVATAR_RELATIONSHIP_COMPLETE_UNIT_MAX_PX
            || (float)$unit['height'] > AVATAR_RELATIONSHIP_COMPLETE_UNIT_MAX_PX) {
            return ['ok' => false, 'width' => $unit['width'], 'height' => $unit['height'], 'reason' => 'complete-unit-limit'];
        }
    }
    if (!$units) return ['ok' => true, 'width' => 0, 'height' => 0, 'columns' => 0, 'rows' => 0];

    $storedOptions = !empty($relationship['options_json'])
        ? (json_decode((string)$relationship['options_json'], true) ?: [])
        : [];
    $spacing = max(0, min(64, (int)($storedOptions['rowSpacing'] ?? 0)));
    $extent = avatar_relationship_capacity_packed_extent($units, $spacing);
    return [
        'ok' => $extent['width'] <= AVATAR_RELATIONSHIP_HARD_EXTENT_MAX_PX
            && $extent['height'] <= AVATAR_RELATIONSHIP_HARD_EXTENT_MAX_PX,
        'width' => $extent['width'],
        'height' => $extent['height'],
        'columns' => $extent['columns'],
        'rows' => $extent['rows'],
        'spacing' => $spacing,
        'reason' => $extent['width'] > AVATAR_RELATIONSHIP_HARD_EXTENT_MAX_PX
            || $extent['height'] > AVATAR_RELATIONSHIP_HARD_EXTENT_MAX_PX
                ? 'relationship-extent-limit'
                : 'supported',
    ];
}

function avatar_relationship_capacity_packed_extent(array $units, int $spacing): array {
    if (!$units) return ['width' => 0.0, 'height' => 0.0, 'columns' => 0, 'rows' => 0];
    $spacing = max(0, min(64, $spacing));
    $columns = max(1, (int)ceil(sqrt(count($units))));
    $rows = array_chunk($units, $columns);
    $width = 0.0;
    $height = 0.0;
    foreach ($rows as $index => $row) {
        $rowWidth = array_sum(array_map(static fn(array $unit): float => (float)$unit['width'], $row))
            + max(0, count($row) - 1) * $spacing;
        $rowHeight = max(array_map(static fn(array $unit): float => (float)$unit['height'], $row));
        $width = max($width, $rowWidth);
        $height += $rowHeight + ($index < count($rows) - 1 ? $spacing : 0);
    }
    return [
        'width' => $width,
        'height' => $height,
        'columns' => $columns,
        'rows' => count($rows),
    ];
}

function avatar_relationship_capacity_projected_members(
    array $members,
    array $target,
    string $relationshipRole,
    ?int $lapHostParticipantId,
    ?string $lapSide
): array {
    $projected = $members;
    $nextOrder = $members
        ? max(array_map(static fn(array $member): int => (int)($member['member_order'] ?? 0), $members)) + 1
        : 0;
    $projected[] = [
        'participant_id' => (int)$target['id'],
        'relationship_role' => $relationshipRole,
        'membership_status' => 'active',
        'member_order' => $nextOrder,
        'lap_host_participant_id' => $relationshipRole === 'lap' ? $lapHostParticipantId : null,
        'lap_side' => $relationshipRole === 'lap' ? $lapSide : null,
        'anchor_json' => null,
    ];
    return $projected;
}

function avatar_relationship_capacity_admission(
    PDO $pdo,
    array $relationship,
    array $members,
    array $target,
    string $relationshipRole,
    ?int $lapHostParticipantId,
    ?string $lapSide
): array {
    if ($relationshipRole === 'normal') {
        $limit = (int)avatar_relationship_capacity_policy($pdo)['maximumRegularAvatarLinks'];
        if (avatar_relationship_capacity_regular_member_count($members) + 1 > $limit) {
            return avatar_relationship_capacity_limit_error($limit);
        }
    }
    $projectedMembers = avatar_relationship_capacity_projected_members(
        $members,
        $target,
        $relationshipRole,
        $lapHostParticipantId,
        $lapSide
    );
    $projection = avatar_relationship_capacity_geometry_projection($pdo, $relationship, $projectedMembers, [$target]);
    return !empty($projection['ok'])
        ? ['ok' => true, 'geometry' => $projection]
        : avatar_relationship_capacity_geometry_error($projection);
}

function avatar_relationship_capacity_pair_admission(
    PDO $pdo,
    array $initiator,
    array $target,
    string $mode,
    ?string $lapSide
): array {
    $relationship = ['options_json' => '{}', 'metadata_json' => '{}'];
    $members = $mode === 'lap'
        ? [
            [
                'participant_id' => (int)$target['id'],
                'relationship_role' => 'normal',
                'membership_status' => 'active',
                'member_order' => 0,
                'lap_host_participant_id' => null,
                'lap_side' => null,
                'anchor_json' => null,
            ],
            [
                'participant_id' => (int)$initiator['id'],
                'relationship_role' => 'lap',
                'membership_status' => 'active',
                'member_order' => 1,
                'lap_host_participant_id' => (int)$target['id'],
                'lap_side' => $lapSide,
                'anchor_json' => null,
            ],
        ]
        : [
            [
                'participant_id' => (int)$initiator['id'],
                'relationship_role' => 'normal',
                'membership_status' => 'active',
                'member_order' => 0,
                'lap_host_participant_id' => null,
                'lap_side' => null,
                'anchor_json' => null,
            ],
            [
                'participant_id' => (int)$target['id'],
                'relationship_role' => 'normal',
                'membership_status' => 'active',
                'member_order' => 1,
                'lap_host_participant_id' => null,
                'lap_side' => null,
                'anchor_json' => null,
            ],
        ];
    $limit = (int)avatar_relationship_capacity_policy($pdo)['maximumRegularAvatarLinks'];
    if (avatar_relationship_capacity_regular_member_count($members) > $limit) {
        return avatar_relationship_capacity_limit_error($limit);
    }
    $projection = avatar_relationship_capacity_geometry_projection($pdo, $relationship, $members, [$initiator, $target]);
    return !empty($projection['ok'])
        ? ['ok' => true, 'geometry' => $projection]
        : avatar_relationship_capacity_geometry_error($projection);
}
