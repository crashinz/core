<?php
require_once __DIR__ . '/../includes/base.php';

$me = require_staff();
$pdo = db();

function admin_settings(PDO $pdo): array {
    $rows = $pdo->query('SELECT setting_key, value FROM app_settings ORDER BY setting_key ASC')->fetchAll();
    $settings = [];
    foreach ($rows as $row) $settings[$row['setting_key']] = $row['value'];
    return $settings;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'overview');

    if ($action === 'settings') {
        json_out([
            'settings' => admin_settings($pdo),
            'avatarSizePolicy' => avatar_size_policy($pdo),
        ]);
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

function broadcast_role_colors(PDO $pdo): void {
    $colors = role_color_settings($pdo);
    foreach ($pdo->query('SELECT id FROM room_sessions')->fetchAll() as $session) {
        emit_event($pdo, (int)$session['id'], 'role_colors_update', $colors);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
security_require_recent_authentication_or_json();

$body = input_json();
$action = (string)($body['action'] ?? '');

if ($action === 'save_role_colors' || $action === 'reset_role_colors') {
    if ((string)$me['role'] !== 'admin') json_out(['error' => 'Administrator required'], 403);
    $result = role_color_validate_settings($body, $action === 'reset_role_colors');
    if (empty($result['ok'])) {
        $status = (int)($result['http_status'] ?? 400);
        unset($result['http_status']);
        json_out($result, $status);
    }
    set_app_setting($pdo, 'role_colors_mode', $result['mode']);
    foreach ($result['palette'] as $role => $colors) {
        set_app_setting($pdo, "role_color_{$role}_bg", $colors['background']);
        set_app_setting($pdo, "role_color_{$role}_text", $colors['text']);
    }
    broadcast_role_colors($pdo);
    log_tool($pdo, (int)$me['id'], 'admin_role_colors_update', null, null, $action === 'reset_role_colors' ? 'Reset username role colors' : 'Updated username role colors');
    json_out(['ok' => true, 'roleColors' => role_color_settings($pdo), 'settings' => admin_settings($pdo)]);
}

if ($action === 'save_diagnostic_screenshots') {
    if ((string)$me['role'] !== 'admin') json_out(['error' => 'Administrator required'], 403);
    $enabled = !empty($body['diagnostic_screenshots_enabled']);
    $retention = (int)($body['diagnostic_screenshot_retention_days'] ?? 0);
    if ($enabled && ($retention < 1 || $retention > 365)) json_out(['error' => 'Enabled screenshots require a retention period from 1 to 365 days.'], 400);
    if (!$enabled && ($retention < 0 || $retention > 365)) json_out(['error' => 'Retention must be from 0 to 365 days.'], 400);
    set_app_setting($pdo, 'diagnostic_screenshots_enabled', $enabled ? '1' : '0');
    set_app_setting($pdo, 'diagnostic_screenshot_retention_days', (string)$retention);
    log_tool($pdo, (int)$me['id'], 'admin_diagnostic_screenshot_settings', null, null, $enabled ? "Enabled censored screenshots with {$retention}-day retention" : 'Disabled censored screenshots');
    json_out(['ok' => true, 'settings' => admin_settings($pdo)]);
}

if ($action === 'save_settings') {
    $policyValidation = avatar_size_policy_validate_settings($body);
    if (empty($policyValidation['ok'])) {
        $status = (int)($policyValidation['http_status'] ?? 400);
        unset($policyValidation['http_status']);
        json_out($policyValidation, $status);
    }
    $allowed = [
        'chat_posts_per_second' => [0.2, 30],
        'room_chat_history_limit' => [1, 1000],
        'avatar_movements_per_second' => [1, 60],
        'avatar_max_size_mb' => [0.5, 50],
        'gesture_upload_limit' => [0, 1000],
        'room_image_max_size_mb' => [1, 100],
        'room_video_max_size_mb' => [5, 1000],
        'participant_idle_timeout_minutes' => [0.5, 120],
        'auth_login_max_attempts' => [1, 50],
        'auth_recovery_max_attempts' => [1, 50],
        'auth_ip_max_attempts' => [5, 500],
        'auth_attempt_window_minutes' => [1, 1440],
        'auth_lockout_minutes' => [1, 1440],
        'age_gate_min_age' => [1, 120],
    ];
    foreach ($allowed as $key => [$min, $max]) {
        $value = (float)($body[$key] ?? app_setting($pdo, $key, (string)$min));
        $value = max($min, min($max, $value));
        set_app_setting($pdo, $key, (string)$value);
    }
    $giphyKey = trim((string)($body['gif_giphy_api_key'] ?? ''));
    $tenorKey = trim((string)($body['gif_tenor_api_key'] ?? ''));
    $klipyKey = trim((string)($body['gif_klipy_api_key'] ?? ''));
    $provider = (string)($body['gif_default_provider'] ?? 'giphy');
    if (!in_array($provider, ['giphy', 'klipy', 'tenor'], true)) $provider = 'giphy';
    set_app_setting($pdo, 'gif_giphy_api_key', $giphyKey);
    set_app_setting($pdo, 'gif_tenor_api_key', $tenorKey);
    set_app_setting($pdo, 'gif_klipy_api_key', $klipyKey);
    set_app_setting($pdo, 'gif_default_provider', $provider);
    set_app_setting($pdo, 'age_gate_enabled', !empty($body['age_gate_enabled']) ? '1' : '0');
    $sizePolicyResult = avatar_size_policy_update($pdo, $body);
    if (empty($sizePolicyResult['ok'])) {
        $status = (int)($sizePolicyResult['http_status'] ?? 400);
        unset($sizePolicyResult['http_status']);
        json_out($sizePolicyResult, $status);
    }
    log_tool($pdo, (int)$me['id'], 'admin_settings_update', null, null, 'Updated community settings');
    json_out([
        'ok' => true,
        'settings' => admin_settings($pdo),
        'avatarSizePolicy' => $sizePolicyResult['policy'],
    ]);
}

if ($action === 'reset_avatar_size_policy') {
    $result = avatar_size_policy_update($pdo, [], true);
    if (empty($result['ok'])) {
        $status = (int)($result['http_status'] ?? 400);
        unset($result['http_status']);
        json_out($result, $status);
    }
    log_tool(
        $pdo,
        (int)$me['id'],
        'admin_avatar_size_policy_reset',
        null,
        null,
        'Reset avatar and webcam size policy defaults'
    );
    json_out([
        'ok' => true,
        'settings' => admin_settings($pdo),
        'avatarSizePolicy' => $result['policy'],
    ]);
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
