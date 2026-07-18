<?php
require_once __DIR__ . '/includes/base.php';
$user = require_staff();
$pdo = db();
$return = (string)($_GET['return'] ?? 'lobby');
$roomKey = trim((string)($_GET['id'] ?? ''));
$back = $return === 'room' && $roomKey !== '' ? app_url('/chatroom.php?id=' . rawurlencode($roomKey)) : app_url('/lobby.php');
$assetVersion = static fn(string $path): string => app_url($path) . '?v=' . rawurlencode((string)(is_file(__DIR__ . $path) ? filemtime(__DIR__ . $path) : time()));
$roleColors = role_color_settings($pdo);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - ChatSpace CE</title>
  <link rel="stylesheet" href="<?= e($assetVersion('/assets/css/styles.css')) ?>">
</head>
<body class="shared-surface-body" data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>" data-is-admin="<?= $user['role'] === 'admin' ? 'true' : 'false' ?>" data-role-colors-mode="<?= e($roleColors['mode']) ?>" style="<?= e(role_color_css_variables($pdo)) ?>">
<main class="shared-surface admin-shared-surface">
  <header class="shared-surface-head">
    <div><div class="side-title">ChatSpace Operations</div><h1>Admin</h1></div>
    <a class="btn" href="<?= e($back) ?>">Back</a>
  </header>
  <nav class="shared-tabs admin-shared-tabs" aria-label="Admin sections">
    <button class="active" data-admin-tab="users" type="button">Users</button>
    <button data-admin-tab="settings" type="button">Settings</button>
    <button data-admin-tab="appearance" type="button">Appearance</button>
    <button data-admin-tab="database" type="button">Database</button>
    <button data-admin-tab="link-icons" type="button">Link Icons</button>
    <button data-admin-tab="moderation" type="button">Moderation</button>
    <button data-admin-tab="logs" type="button">Tool Logs</button>
    <button data-admin-tab="errors" type="button">Errors <span class="admin-tab-badge" id="issue-count" aria-label="0 issues">0</span></button>
  </nav>
  <div class="shared-status" id="admin-page-status" role="status"></div>

  <section class="shared-panel" data-admin-panel="errors">
    <div class="admin-chat-diagnostic-tabs" aria-label="Communication diagnostics"><span>Room Chat</span><span>Community Chat</span><strong>Errors</strong></div>
    <div class="issue-workspace">
      <aside>
        <label>Status <select id="issue-status-filter"><option value="">All</option><option value="new">New</option><option value="confirmed">Confirmed</option><option value="investigating">Investigating</option><option value="fixed-pending-verification">Fixed pending verification</option><option value="resolved">Resolved</option><option value="expected">Expected</option><option value="ignored">Ignored</option><option value="regressed">Regressed</option></select></label>
        <div id="issue-list" class="issue-list"></div>
      </aside>
      <article id="issue-detail" class="issue-detail"><p class="minor">Select an issue.</p></article>
    </div>
  </section>

  <section class="shared-panel active" data-admin-panel="users">
    <h2>Users</h2>
    <form id="admin-user-create" class="shared-form inline-form">
      <input name="display_name" placeholder="Display name" required>
      <input name="email" type="email" placeholder="Email" required>
      <input name="password" type="password" placeholder="Password" minlength="8" required>
      <select name="role"><option value="user">User</option><option value="guide">Guide</option><option value="developer">Developer</option><option value="admin">Admin</option></select>
      <button class="btn btn-primary" type="submit">Add User</button>
    </form>
    <div id="admin-user-list" class="admin-scroll-list"></div>
  </section>

  <section class="shared-panel" data-admin-panel="settings">
    <h2>Operational Settings</h2>
    <form id="admin-settings-form" class="shared-form settings-grid">
      <?php foreach ([
        'chat_posts_per_second' => 'Chat posts per second', 'room_chat_history_limit' => 'Room chat history posts',
        'avatar_movements_per_second' => 'Avatar movements per second', 'participant_idle_timeout_minutes' => 'Idle removal minutes',
        'avatar_max_size_mb' => 'Avatar upload max MB', 'avatar_upload_max_width_px' => 'Avatar upload max width px',
        'avatar_upload_max_height_px' => 'Avatar upload max height px', 'avatar_display_max_px' => 'Avatar display max edge px',
        'webcam_display_max_width_px' => 'Webcam display max width px', 'webcam_display_max_height_px' => 'Webcam display max height px',
        'gesture_upload_limit' => 'Gestures per account', 'room_image_max_size_mb' => 'Room image max MB',
        'room_video_max_size_mb' => 'Room video max MB', 'auth_login_max_attempts' => 'Login attempts',
        'auth_recovery_max_attempts' => 'Recovery attempts', 'auth_ip_max_attempts' => 'Attempts per IP',
        'auth_attempt_window_minutes' => 'Attempt window minutes', 'auth_lockout_minutes' => 'Lockout minutes',
        'age_gate_min_age' => 'Age gate minimum age',
      ] as $key => $label): ?>
      <label><?= e($label) ?><input name="<?= e($key) ?>" type="number" step="any" required></label>
      <?php endforeach; ?>
      <label>Default GIF provider <select name="gif_default_provider"><option value="giphy">GIPHY</option><option value="klipy">Klipy</option><option value="tenor">Tenor</option></select></label>
      <label>GIPHY key <input name="gif_giphy_api_key" type="password"></label>
      <label>Tenor key <input name="gif_tenor_api_key" type="password"></label>
      <label>Klipy key <input name="gif_klipy_api_key" type="password"></label>
      <label class="settings-checkbox-row"><strong>Enable age gate</strong><input name="age_gate_enabled" type="checkbox"><span>Require users to confirm they meet the configured minimum age.</span></label>
      <div class="shared-form-actions"><button class="btn btn-primary" type="submit">Save Settings</button><button class="btn" id="reset-size-policy" type="button">Reset Display Size Defaults</button></div>
    </form>
  </section>

  <section class="shared-panel" data-admin-panel="appearance">
    <h2>Username Role Colors</h2>
    <form id="role-color-form" class="shared-form">
      <label>Mode <select name="role_colors_mode"><option value="enabled">Enabled</option><option value="disabled">Disabled</option><option value="custom">Custom</option></select></label>
      <div class="role-color-grid">
      <?php foreach (['admin' => 'Administrator', 'developer' => 'Developer', 'guide' => 'Guide', 'owner' => 'Room Owner', 'user' => 'Standard User'] as $role => $label): ?>
        <fieldset><legend><?= e($label) ?></legend><label>Background <input name="role_color_<?= e($role) ?>_bg" type="color"></label><label>Text <input name="role_color_<?= e($role) ?>_text" type="color"></label><span class="role-color-preview role-<?= e($role) ?>"><?= e($label) ?></span></fieldset>
      <?php endforeach; ?>
      </div>
      <div class="shared-form-actions"><button class="btn btn-primary" type="submit">Save Colors</button><button class="btn" id="reset-role-colors" type="button">Reset Defaults</button></div>
    </form>
    <form id="diagnostic-screenshot-form" class="shared-form compact-form">
      <label class="settings-checkbox-row"><strong>Diagnostic Screenshots</strong><input name="diagnostic_screenshots_enabled" type="checkbox"><span>Enable locally censored schematic screenshots</span></label>
      <label>Unresolved retention days <input name="diagnostic_screenshot_retention_days" type="number" min="0" max="365" value="0"></label>
      <p class="minor">Capture remains disabled until a retention period is selected. Uncensored page pixels are never created.</p>
      <button class="btn btn-primary" type="submit">Save Screenshot Policy</button>
    </form>
  </section>

  <section class="shared-panel" data-admin-panel="database">
    <h2>Database</h2>
    <div class="admin-actions"><a class="btn btn-primary" href="<?= e(app_url('/api/admin_database.php?action=download')) ?>">Full Backup</a><a class="btn" href="<?= e(app_url('/api/admin_database.php?action=export_bundle&users=1&rooms=1&settings=1')) ?>">Portable Export</a></div>
    <form id="admin-database-import" class="shared-form compact-form" enctype="multipart/form-data"><label>Import backup <input name="database" type="file" accept=".sqlite,.db,.json" required></label><button class="btn btn-danger" type="submit">Import</button></form>
  </section>

  <section class="shared-panel" data-admin-panel="link-icons">
    <h2>Link Pairing Icons</h2>
    <form id="link-icon-create" class="shared-form inline-form" enctype="multipart/form-data"><input name="label" placeholder="Icon label" required><input name="icon" type="file" accept="image/png,image/webp,image/gif,image/jpeg" required><button class="btn btn-primary" type="submit">Add Icon</button></form>
    <div id="link-icon-list" class="admin-scroll-list"></div>
  </section>

  <section class="shared-panel" data-admin-panel="moderation"><h2>Moderation</h2><div class="admin-moderation-grid"><section><h3>User Blocks</h3><div id="admin-blocks"></div></section><section><h3>Room Kicks</h3><div id="admin-room-ejections"></div></section><section><h3>Community Ejections</h3><div id="admin-community-ejections"></div></section></div></section>
  <section class="shared-panel" data-admin-panel="logs"><h2>Tool Logs</h2><div id="admin-tool-logs" class="admin-scroll-list"></div></section>
</main>
<script src="<?= e($assetVersion('/assets/js/admin.js')) ?>"></script>
</body>
</html>
