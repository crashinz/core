<?php
require_once __DIR__ . '/includes/base.php';
$user = require_user();
$pdo = db();
$branding = private_site_branding_projection($pdo, 'other');
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
  <title><?= e(branded_page_title('Account', $pdo, 'other')) ?></title>
  <link rel="stylesheet" href="<?= e($assetVersion('/assets/css/styles.css')) ?>">
</head>
<body class="shared-surface-body" data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>" data-role-colors-mode="<?= e($roleColors['mode']) ?>" style="<?= e(role_color_css_variables($pdo)) ?>">
<main class="shared-surface">
  <header class="shared-surface-head">
    <div><div class="side-title"><?= e($branding['effective_name'] === 'ChatSpace Community Edition' ? 'ChatSpace' : $branding['effective_name']) ?></div><h1>Account</h1></div>
    <a class="btn" href="<?= e($back) ?>">Back</a>
  </header>
  <nav class="shared-tabs" aria-label="Account sections">
    <button class="active" data-account-tab="profile" type="button">Public Profile</button>
    <button data-account-tab="security" type="button">Security</button>
    <button data-account-tab="status" type="button">Account Status</button>
  </nav>
  <div class="shared-status" id="account-page-status" role="status"></div>
  <section class="shared-panel active" data-account-panel="profile">
    <h2>Public Profile</h2>
    <p class="minor">This information is visible to authenticated community members who can open your profile. Private login, recovery, authentication, security, moderation, IP-address, and device information is never part of the public profile.</p>
    <div class="account-avatar-row">
      <img id="account-avatar" src="<?= e(resolve_avatar($user['avatar_path'] ?? null)) ?>" alt="Current avatar">
      <div><strong>Current avatar</strong><p class="minor">Avatar image and display controls remain available from your avatar menu inside a room.</p><a class="btn" href="<?= e($back) ?>">Change in Room</a></div>
    </div>
    <form id="account-profile-form" class="shared-form">
      <label>Username <input name="username" readonly aria-readonly="true" autocomplete="username"></label>
      <label>Display name <input name="display_name" autocomplete="nickname" aria-describedby="profile-display-name-count"><span class="profile-field-count" id="profile-display-name-count" data-profile-counter="display_name"></span></label>
      <p class="minor field-help" id="account-profile-display-fallback"></p>
      <label>Name <input name="name" autocomplete="name" aria-describedby="profile-name-help profile-name-count"><span class="profile-field-count" id="profile-name-count" data-profile-counter="name"></span></label>
      <p class="minor field-help" id="profile-name-help">This optional name is shown only on your member profile. You may enter a first name, real name, preferred name, nickname, or another name you want profile viewers to see. It is not required or verified.</p>
      <label>Location <input name="location" autocomplete="address-level2" aria-describedby="profile-location-count"><span class="profile-field-count" id="profile-location-count" data-profile-counter="location"></span></label>
      <label>About Me <textarea name="about_me" rows="6" aria-describedby="profile-about-me-count"></textarea><span class="profile-field-count" id="profile-about-me-count" data-profile-counter="about_me"></span></label>
      <label>Public profile contact email <input name="public_contact_email" type="email" autocomplete="off" aria-describedby="profile-email-help profile-public-contact-email-count"><span class="profile-field-count" id="profile-public-contact-email-count" data-profile-counter="public_contact_email"></span></label>
      <p class="minor field-help" id="profile-email-help">This email is shown on your member profile. It is separate from the private email used for login and account recovery.</p>
      <label>Website <input name="website" type="url" inputmode="url" placeholder="https://example.com" aria-describedby="profile-website-count"><span class="profile-field-count" id="profile-website-count" data-profile-counter="website"></span></label>
      <label>Interests <textarea name="interests" rows="4" aria-describedby="profile-interests-count"></textarea><span class="profile-field-count" id="profile-interests-count" data-profile-counter="interests"></span></label>
      <div class="account-profile-readonly">
        <div><span>Registered</span><strong id="account-profile-registered"></strong></div>
        <div>
          <span>Previous display names</span>
          <ul id="account-profile-history" class="account-profile-history" aria-live="polite"></ul>
        </div>
      </div>
      <div class="shared-form-actions">
        <button class="btn btn-primary" type="submit">Save Public Profile</button>
        <button class="btn" id="account-profile-cancel" type="button">Cancel</button>
      </div>
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
