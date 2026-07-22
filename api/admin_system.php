<?php
require_once __DIR__ . '/../includes/base.php';

$me = require_staff();
$pdo = db();

function admin_settings(PDO $pdo): array {
    $secretKeys = [];
    foreach (settings_registry_definitions() as $definition) {
        if (!empty($definition['secret']) && $definition['settingKey'] !== null) $secretKeys[(string)$definition['settingKey']] = true;
    }
    $rows = $pdo->query('SELECT setting_key, value FROM app_settings ORDER BY setting_key ASC')->fetchAll();
    $settings = [];
    foreach ($rows as $row) $settings[$row['setting_key']] = isset($secretKeys[$row['setting_key']]) ? '' : $row['value'];
    return $settings;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'overview');

    if ($action === 'settings') {
        json_out([
            'settings' => admin_settings($pdo),
            'settingsRegistry' => settings_registry_snapshot($pdo, 'admin'),
            'avatarSizePolicy' => avatar_size_policy($pdo),
            'webcamCapability' => webcam_capability($pdo),
            'relationshipCapacity' => avatar_relationship_capacity_policy($pdo),
            'danceCapability' => avatar_dance_capability_policy($pdo),
        ]);
    }

    if ($action === 'settings_registry') {
        json_out(['settingsRegistry' => settings_registry_snapshot($pdo, 'admin')]);
    }

    if ($action === 'relationship_capacity_impact') {
        if ((string)$me['role'] !== 'admin') json_out(['error' => 'Administrator required'], 403);
        $result = avatar_relationship_capacity_impact($pdo, $_GET['value'] ?? null);
        $status = max(200, (int)($result['http_status'] ?? 200));
        unset($result['http_status']);
        json_out($result, $status);
    }

    if ($action === 'logs') {
        $rows = $pdo->query(
            'SELECT tl.*, actor.display_name AS actor_name, target.display_name AS target_name, r.name AS room_name
               FROM tool_logs tl
               LEFT JOIN users actor ON actor.id = tl.actor_user_id
               LEFT JOIN users target ON target.id = tl.target_user_id
               LEFT JOIN rooms r ON r.id = tl.room_id
              ORDER BY tl.id DESC'
        )->fetchAll();
        json_out(['logs' => array_map(fn(array $row): array => [
            'id' => (int)$row['id'],
            'action' => $row['action'],
            'actor_name' => $row['actor_name'] ?: 'System',
            'target_name' => $row['target_name'] ?: '',
            'room_name' => $row['room_name'] ?: '',
            'detail' => $row['detail'] ?: '',
            'created_at' => $row['created_at'],
        ], $rows)]);
    }

    if ($action === 'blocks') {
        $blocks = $pdo->query(
            'SELECT ub.blocker_user_id, ub.blocked_user_id, ub.created_at,
                    blocker.display_name AS blocker_name, blocked.display_name AS blocked_name
               FROM user_blocks ub
               JOIN users blocker ON blocker.id = ub.blocker_user_id
               JOIN users blocked ON blocked.id = ub.blocked_user_id
              ORDER BY ub.created_at DESC'
        )->fetchAll();
        json_out(['blocks' => array_map(fn(array $row): array => [
            'blocker_user_id' => (int)$row['blocker_user_id'],
            'blocked_user_id' => (int)$row['blocked_user_id'],
            'blocker_name' => $row['blocker_name'],
            'blocked_name' => $row['blocked_name'],
            'created_at' => $row['created_at'],
        ], $blocks)]);
    }

    if ($action === 'community_ejections') {
        $rows = $pdo->query(
            'SELECT ce.*, u.display_name, by_user.display_name AS ejected_by_name
               FROM community_ejections ce
               JOIN users u ON u.id = ce.user_id
               JOIN users by_user ON by_user.id = ce.ejected_by_user_id
              WHERE ' . active_ejection_sql('ce') . '
              ORDER BY ce.created_at DESC'
        )->fetchAll();
        json_out(['ejections' => array_map(fn(array $row): array => [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'display_name' => $row['display_name'],
            'ejected_by_name' => $row['ejected_by_name'],
            'duration_minutes' => $row['duration_minutes'] !== null ? (int)$row['duration_minutes'] : null,
            'permanent' => (bool)$row['permanent'],
            'reason' => $row['reason'] ?: '',
            'created_at' => $row['created_at'],
            'expires_at' => $row['expires_at'],
        ], $rows)]);
    }

    if ($action === 'room_ejections') {
        $rows = $pdo->query(
            'SELECT re.*, u.display_name, by_user.display_name AS ejected_by_name, r.name AS room_name
               FROM room_ejections re
               JOIN users u ON u.id = re.user_id
               JOIN users by_user ON by_user.id = re.ejected_by_user_id
               JOIN rooms r ON r.id = re.room_id
              WHERE ' . active_ejection_sql('re') . '
              ORDER BY re.created_at DESC'
        )->fetchAll();
        json_out(['ejections' => array_map(fn(array $row): array => [
            'id' => (int)$row['id'],
            'room_id' => (int)$row['room_id'],
            'user_id' => (int)$row['user_id'],
            'display_name' => $row['display_name'],
            'room_name' => $row['room_name'],
            'ejected_by_name' => $row['ejected_by_name'],
            'duration_minutes' => $row['duration_minutes'] !== null ? (int)$row['duration_minutes'] : null,
            'permanent' => (bool)$row['permanent'],
            'created_at' => $row['created_at'],
            'expires_at' => $row['expires_at'],
        ], $rows)]);
    }

    if ($action === 'relationship_repair') {
        $sessionId = isset($_GET['session_id']) && $_GET['session_id'] !== '' ? (int)$_GET['session_id'] : null;
        json_out(['relationshipRepair' => avatar_relationship_repair($pdo, ['session_id' => $sessionId, 'apply' => false])]);
    }

    json_out(['error' => 'Unknown action'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
security_require_recent_authentication_or_json();

$body = input_json();
$action = (string)($body['action'] ?? '');

if ($action === 'update_settings_registry') {
    if ((string)$me['role'] !== 'admin') json_out(['error' => 'Administrator required'], 403);
    $result = settings_registry_update(
        $pdo,
        [
            'operation' => (string)($body['operation'] ?? ''),
            'values' => is_array($body['values'] ?? null) ? $body['values'] : [],
            'setting_id' => isset($body['setting_id']) ? (string)$body['setting_id'] : null,
            'category_id' => isset($body['category_id']) ? (string)$body['category_id'] : null,
            'subsection_id' => isset($body['subsection_id']) ? (string)$body['subsection_id'] : null,
            'preset' => isset($body['preset']) ? (string)$body['preset'] : null,
            'confirmed' => !empty($body['confirmed']),
            'capacity_confirmed' => !empty($body['capacity_confirmed']),
        ],
        $body['expected_revision'] ?? null,
        (int)$me['id'],
        'admin'
    );
    if (empty($result['ok'])) {
        $status = max(400, (int)($result['http_status'] ?? 400));
        unset($result['http_status']);
        json_out($result, $status);
    }
    json_out($result + [
        'settings' => admin_settings($pdo),
        'avatarSizePolicy' => avatar_size_policy($pdo),
        'webcamCapability' => webcam_capability($pdo),
        'relationshipCapacity' => avatar_relationship_capacity_policy($pdo),
        'danceCapability' => avatar_dance_capability_policy($pdo),
    ]);
}

$legacySettingsActions = [
    'save_role_colors', 'reset_role_colors', 'save_diagnostic_screenshots',
    'save_webcam_capability', 'save_relationship_capacity', 'update_dance_capabilities',
    'save_settings', 'reset_avatar_size_policy',
];
if (in_array($action, $legacySettingsActions, true)) {
    json_out([
        'ok' => false,
        'code' => 'SETTINGS_REGISTRY_REQUIRED',
        'error' => 'This settings client is outdated. Refresh Admin and retry through the shared settings registry.',
        'settingsRegistry' => settings_registry_snapshot($pdo, 'admin'),
    ], 409);
}

if ($action === 'remove_block') {
    $blockerId = (int)($body['blocker_user_id'] ?? 0);
    $blockedId = (int)($body['blocked_user_id'] ?? 0);
    if (!$blockerId || !$blockedId) json_out(['error' => 'Block required'], 400);
    $pdo->prepare('DELETE FROM user_blocks WHERE blocker_user_id = ? AND blocked_user_id = ?')->execute([$blockerId, $blockedId]);
    log_tool($pdo, (int)$me['id'], 'admin_remove_block', $blockedId, null, 'Removed user block');
    json_out(['ok' => true]);
}

if ($action === 'undo_community_ejection') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['error' => 'Ejection required'], 400);
    $stmt = $pdo->prepare('SELECT user_id FROM community_ejections WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $targetId = (int)($stmt->fetchColumn() ?: 0);
    $pdo->prepare('DELETE FROM community_ejections WHERE id = ?')->execute([$id]);
    log_tool($pdo, (int)$me['id'], 'admin_undo_community_ejection', $targetId ?: null, null, 'Undid community ejection');
    json_out(['ok' => true]);
}

if ($action === 'undo_room_ejection') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['error' => 'Ejection required'], 400);
    $stmt = $pdo->prepare('SELECT user_id, room_id FROM room_ejections WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $pdo->prepare('DELETE FROM room_ejections WHERE id = ?')->execute([$id]);
    log_tool($pdo, (int)$me['id'], 'admin_undo_room_kick', $row ? (int)$row['user_id'] : null, $row ? (int)$row['room_id'] : null, 'Undid room kick');
    json_out(['ok' => true]);
}

if ($action === 'relationship_repair') {
    $sessionId = isset($body['session_id']) && $body['session_id'] !== '' ? (int)$body['session_id'] : null;
    $apply = !empty($body['apply']) || !empty($body['repair']);
    $result = avatar_relationship_repair($pdo, ['session_id' => $sessionId, 'apply' => $apply]);
    if ($apply) {
        log_tool(
            $pdo,
            (int)$me['id'],
            'admin_avatar_relationship_repair',
            null,
            null,
            ($sessionId !== null ? 'Session ' . $sessionId . ': ' : 'Database: ')
                . 'repaired/synced ' . (int)$result['summary']['created_or_synced_count']
                . ', removed ' . (int)$result['summary']['removed_or_would_remove_count']
                . ', skipped ' . (int)$result['summary']['skipped_count']
        );
    }
    json_out(['relationshipRepair' => $result]);
}

json_out(['error' => 'Unknown action'], 400);
