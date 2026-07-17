<?php
require_once __DIR__ . '/includes/base.php';
$user = require_user();
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
  <title>Account - ChatSpace CE</title>
  <link rel="stylesheet" href="<?= e($assetVersion('/assets/css/styles.css')) ?>">
</head>
<body class="shared-surface-body" data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>" data-role-colors-mode="<?= e($roleColors['mode']) ?>" style="<?= e(role_color_css_variables($pdo)) ?>">
<main class="shared-surface">
  <header class="shared-surface-head">
    <div><div class="side-title">ChatSpace</div><h1>Account</h1></div>
    <a class="btn" href="<?= e($back) ?>">Back</a>
  </header>
  <nav class="shared-tabs" aria-label="Account sections">
    <button class="active" data-account-tab="profile" type="button">Profile</button>
    <button data-account-tab="security" type="button">Security</button>
    <button data-account-tab="status" type="button">Account Status</button>
  </nav>
  <div class="shared-status" id="account-page-status" role="status"></div>
  <section class="shared-panel active" data-account-panel="profile">
    <h2>Profile</h2>
    <div class="account-avatar-row">
      <img id="account-avatar" src="<?= e(resolve_avatar($user['avatar_path'] ?? null)) ?>" alt="Current avatar">
      <div><strong>Current avatar</strong><p class="minor">Avatar image and display controls remain available from your avatar menu inside a room.</p><a class="btn" href="<?= e($back) ?>">Change in Room</a></div>
    </div>
    <form id="account-profile-form" class="shared-form">
      <label>Username <input name="username" required minlength="3" maxlength="32" pattern="[a-z0-9][a-z0-9_.\x2d]{2,31}" autocomplete="username"></label>
      <label>Display name <input name="display_name" required maxlength="80"></label>
      <label>Location <input name="location" maxlength="80"></label>
      <label>About Me <textarea name="about" rows="5" maxlength="500"></textarea></label>
      <label>Profile visibility <select name="visibility"><option value="private">Private</option><option value="community">Community</option><option value="public">Public</option></select></label>
      <button class="btn btn-primary" type="submit">Save Profile</button>
    </form>
  </section>
  <section class="shared-panel" data-account-panel="security">
    <h2>Email</h2>
    <form id="account-email-form" class="shared-form compact-form">
      <label>Email <input name="email" type="email" required autocomplete="email"></label>
      <label>Current password <input name="current_password" type="password" required autocomplete="current-password"></label>
      <button class="btn btn-primary" type="submit">Update Email</button>
    </form>
    <h2>Password</h2>
    <p class="minor" id="password-last-changed"></p>
    <form id="account-password-form" class="shared-form compact-form">
      <label>Old password <input name="old_password" type="password" required autocomplete="current-password"></label>
      <label>New password <input name="new_password" type="password" required minlength="8" autocomplete="new-password"></label>
      <label>Confirm password <input name="confirm_password" type="password" required minlength="8" autocomplete="new-password"></label>
      <button class="btn btn-primary" type="submit">Update Password</button>
    </form>
    <h2>Lost Access Recovery</h2>
    <div class="account-recovery-card" id="account-recovery-card">Checking recovery status…</div>
    <button class="btn" id="account-recovery-generate" type="button">Create Recovery Code</button>
  </section>
  <section class="shared-panel" data-account-panel="status">
    <h2>Account Status</h2>
    <dl class="account-status-list" id="account-status-list"></dl>
    <h2>Current Capabilities</h2>
    <div class="capability-list" id="account-capabilities"></div>
  </section>
</main>
<script src="<?= e($assetVersion('/assets/js/account.js')) ?>"></script>
</body>
</html>
