<?php
require_once __DIR__ . '/../includes/base.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$body = input_json();
$pdo = db();
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$p = auth_participant($pdo, $sessionId, $body['join_token'] ?? '');
$action = $body['action'] ?? 'position';
$allowedLinkIcons = allowed_link_icon_names($pdo);

if ($action === 'position' || $action === 'update_position') {
    $maxMoves = app_setting_float($pdo, 'avatar_movements_per_second', 12);
    $recent = $pdo->prepare("SELECT COUNT(*) FROM events WHERE session_id = ? AND type = 'position' AND payload LIKE ? AND created_at >= ?");
    $recent->execute([$sessionId, '%"participant_id":' . (int)$p['id'] . '%', gmdate('Y-m-d H:i:s', time() - 1)]);
    if ((int)$recent->fetchColumn() >= $maxMoves) json_out(['ok' => true, 'throttled' => true]);
    $x = max(0, min(1, (float)($body['x'] ?? 0)));
    $y = max(0, min(1, (float)($body['y'] ?? 0)));
    $pdo->prepare('UPDATE participants SET position_x = ?, position_y = ? WHERE id = ?')->execute([$x, $y, (int)$p['id']]);
    emit_event($pdo, $sessionId, 'position', ['participant_id' => (int)$p['id'], 'position_x' => $x, 'position_y' => $y]);
    json_out(['ok' => true]);
}

if ($action === 'position_pair') {
    $positions = $body['positions'] ?? [];
    if (!is_array($positions)) json_out(['error' => 'positions required'], 400);

    $allowed = [(int)$p['id'] => true];
    if (!empty($p['linked_to_participant_id'])) $allowed[(int)$p['linked_to_participant_id']] = true;
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE session_id = ? AND linked_to_participant_id = ?');
    $stmt->execute([$sessionId, (int)$p['id']]);
    foreach ($stmt->fetchAll() as $row) $allowed[(int)$row['id']] = true;

    $update = $pdo->prepare('UPDATE participants SET position_x = ?, position_y = ? WHERE id = ? AND session_id = ?');
    $maxMoves = app_setting_float($pdo, 'avatar_movements_per_second', 12);
    $recent = $pdo->prepare("SELECT COUNT(*) FROM events WHERE session_id = ? AND type = 'position' AND created_at >= ?");
    $recent->execute([$sessionId, gmdate('Y-m-d H:i:s', time() - 1)]);
    if ((int)$recent->fetchColumn() >= $maxMoves * 2) json_out(['ok' => true, 'throttled' => true]);
    foreach ($positions as $pos) {
        $id = (int)($pos['participant_id'] ?? 0);
        if (!$id || empty($allowed[$id])) continue;
        $x = max(0, min(1, (float)($pos['x'] ?? 0)));
        $y = max(0, min(1, (float)($pos['y'] ?? 0)));
        $update->execute([$x, $y, $id, $sessionId]);
        emit_event($pdo, $sessionId, 'position', ['participant_id' => $id, 'position_x' => $x, 'position_y' => $y]);
    }
    json_out(['ok' => true]);
}

if ($action === 'relationship_position') {
    $result = avatar_relationship_move_group(
        $pdo,
        $sessionId,
        (int)$p['id'],
        trim((string)($body['relationship_id'] ?? '')),
        (int)($body['expected_version'] ?? 0),
        trim((string)($body['operation_id'] ?? '')),
        is_array($body['positions'] ?? null) ? $body['positions'] : []
    );
    if (empty($result['ok'])) {
        json_out($result, (int)($result['http_status'] ?? 409));
    }
    json_out($result);
}

if ($action === 'link') {
    $targetId = (int)($body['target_participant_id'] ?? 0);
    $linkMode = (string)($body['link_mode'] ?? 'normal');
    if (!in_array($linkMode, ['normal', 'lap'], true)) $linkMode = 'normal';
    $result = avatar_relationship_create_pair_atomic(
        $pdo,
        $sessionId,
        (int)$p['id'],
        $targetId,
        $linkMode,
        $body,
        isset($body['lap_side']) ? (string)$body['lap_side'] : null
    );

    if (empty($result['ok'])) {
        $reason = (string)($result['reason'] ?? 'relationship-conflict');
        $status = $reason === 'blocked' ? 403 : ($reason === 'self' ? 400 : 409);
        $messages = [
            'blocked' => 'You cannot link with this user.',
            'missing-initiator' => 'Your room participant is unavailable.',
            'missing-target' => 'That participant is no longer in the room.',
            'initiator-unavailable' => 'Your room participant is unavailable.',
            'target-unavailable' => 'That participant is no longer available.',
            'already-related' => 'One of these participants is already in a relationship.',
            'initiator-relationship' => 'You are already in a relationship.',
            'target-relationship' => 'That participant is already in a relationship.',
            'self' => 'Target participant required.',
        ];
        json_out(['error' => $messages[$reason] ?? 'That relationship is no longer available.'] + $result, $status);
    }

    json_out($result);
}

if ($action === 'unlink') {
    $relationshipDeparture = avatar_relationship_force_participant_departure(
        $pdo,
        $sessionId,
        (int)$p['id'],
        'legacy-unlink'
    );
    if (empty($relationshipDeparture['ok'])) {
        json_out(
            ['error' => 'Relationship could not be left.'] + $relationshipDeparture,
            (int)($relationshipDeparture['http_status'] ?? 409)
        );
    }
    json_out(['ok' => true, 'relationship_departure' => $relationshipDeparture]);
}

if ($action === 'block_user' || $action === 'unblock_user') {
    $targetUserId = (int)($body['target_user_id'] ?? 0);
    $targetParticipantId = (int)($body['target_participant_id'] ?? 0);
    if (!$targetUserId && $targetParticipantId) {
        $stmt = $pdo->prepare('SELECT user_id FROM participants WHERE id = ? AND session_id = ? LIMIT 1');
        $stmt->execute([$targetParticipantId, $sessionId]);
        $targetUserId = (int)($stmt->fetchColumn() ?: 0);
    }
    if (!$targetUserId || $targetUserId === (int)$p['user_id']) json_out(['error' => 'User required'], 400);
    if ($action === 'block_user') {
        $pdo->prepare(db_uses_mysql_syntax($pdo) ? 'INSERT IGNORE INTO user_blocks (blocker_user_id, blocked_user_id) VALUES (?,?)' : 'INSERT OR IGNORE INTO user_blocks (blocker_user_id, blocked_user_id) VALUES (?,?)')
            ->execute([(int)$p['user_id'], $targetUserId]);
        $relationshipDeparture = null;
        $activeRelationship = avatar_relationship_active_for_participant(
            $pdo,
            $sessionId,
            (int)$p['id'],
            (int)$p['id']
        );
        $sharesRelationship = $activeRelationship && array_filter(
            $activeRelationship['members'] ?? [],
            fn(array $member): bool => (int)($member['userId'] ?? 0) === $targetUserId
        );
        if ($sharesRelationship) {
            $relationshipDeparture = avatar_relationship_force_participant_departure(
                $pdo,
                $sessionId,
                (int)$p['id'],
                'member-blocked'
            );
        }
        emit_event($pdo, $sessionId, 'block', [
            'blocker_user_id' => (int)$p['user_id'],
            'blocked_user_id' => $targetUserId,
            'relationship_change' => $relationshipDeparture && !empty($relationshipDeparture['relationship'])
                ? [
                    'relationship_id' => $relationshipDeparture['relationship']['id'] ?? null,
                    'relationship_version' => $relationshipDeparture['relationship']['version'] ?? null,
                    'relationship_status' => $relationshipDeparture['relationship']['status'] ?? null,
                ]
                : null,
        ]);
        log_tool($pdo, (int)$p['user_id'], 'block_user', $targetUserId, null, 'User block');
    } else {
        $pdo->prepare('DELETE FROM user_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?')
            ->execute([(int)$p['user_id'], $targetUserId]);
        emit_event($pdo, $sessionId, 'unblock', ['blocker_user_id' => (int)$p['user_id'], 'blocked_user_id' => $targetUserId]);
        log_tool($pdo, (int)$p['user_id'], 'unblock_user', $targetUserId, null, 'User unblock');
    }
    json_out(['ok' => true, 'target_user_id' => $targetUserId]);
}

if ($action === 'link_icon') {
    $targetId = (int)($body['target_participant_id'] ?? 0);
    $iconName = preg_replace('/[^a-z0-9-]/', '', (string)($body['icon_name'] ?? 'plus')) ?: 'plus';
    if (!in_array($iconName, $allowedLinkIcons, true)) json_out(['error' => 'Unknown icon'], 400);
    if (!$targetId || $targetId === (int)$p['id']) json_out(['error' => 'Linked participant required'], 400);

    $stmt = $pdo->prepare(
        'SELECT id FROM participants
         WHERE session_id = ? AND id = ?
           AND (linked_to_participant_id = ? OR id = (SELECT linked_to_participant_id FROM participants WHERE id = ?))
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $targetId, (int)$p['id'], (int)$p['id']]);
    if (!$stmt->fetch()) json_out(['error' => 'You are not linked to that participant'], 403);

    $linkKey = link_key_for((int)$p['id'], $targetId);
    $activeRelationship = avatar_relationship_active_for_participant($pdo, $sessionId, (int)$p['id'], (int)$p['id']);
    $relationshipId = (string)($activeRelationship['id'] ?? avatar_relationship_public_id_for((int)$p['id'], $targetId));
    $stmt = $pdo->prepare(db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO link_icons (session_id, link_key, icon_name, updated_at) VALUES (?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE icon_name = VALUES(icon_name), updated_at = CURRENT_TIMESTAMP'
        : 'INSERT INTO link_icons (session_id, link_key, icon_name, updated_at) VALUES (?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(session_id, link_key) DO UPDATE SET icon_name = excluded.icon_name, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$sessionId, $linkKey, $iconName]);
    $payload = [
        'link_key' => $linkKey,
        'relationship_id' => $relationshipId,
        'relationship_version' => $activeRelationship['version'] ?? null,
        'participant_id' => (int)$p['id'],
        'target_participant_id' => $targetId,
        'icon_name' => $iconName,
    ];
    emit_event($pdo, $sessionId, 'link_icon', $payload);
    json_out(['ok' => true] + $payload);
}

json_out(['error' => 'Unknown action'], 400);
