<?php
define('CHATSPACE_RESTRICTED_ACCOUNT_ROUTE', true);
require_once __DIR__ . '/includes/base.php';
$user = require_user();
$pdo = db();
$branding = private_site_branding_projection($pdo, 'other');
$return = (string)($_GET['return'] ?? 'lobby');
$roomKey = trim((string)($_GET['id'] ?? ''));
$back = $return === 'room' && $roomKey !== '' ? app_url('/chatroom.php?id=' . rawurlencode($roomKey)) : app_url('/lobby.php');
$assetVersion = static fn(string $path): string => app_url($path) . '?v=' . rawurlencode((string)(is_file(__DIR__ . $path) ? filemtime(__DIR__ . $path) : time()));
$roleColors = role_color_settings($pdo);
$voiceWebcamPolicy = optional_core_voice_webcam_policy($pdo);
$voiceTransmissionAvailable = !empty($voiceWebcamPolicy['transmissionModes']['enabled']);
$webcamAudienceAvailable = !empty($voiceWebcamPolicy['selectiveWebcamAudience']['enabled']);
$voiceWebcamAvailable = $voiceTransmissionAvailable || $webcamAudienceAvailable;
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
    <button data-account-tab="security" type="button">Security &amp; Privacy</button>
    <button data-account-tab="private-chat" type="button">Private Chat Protection</button>
    <button data-account-tab="voice-webcam" type="button"<?= $voiceWebcamAvailable ? '' : ' hidden' ?>>Voice &amp; Webcam</button>
    <button data-account-tab="status" type="button">Account Status</button>
    <button data-account-tab="requests" type="button">Requests &amp; Notices</button>
    <button data-account-tab="safety" type="button">Safety</button>
  </nav>
  <div class="shared-status" id="account-page-status" role="status" tabindex="-1"></div>
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
      <label>Discord username <input name="discord_username" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="profile-discord-help profile-discord-username-count"><span class="profile-field-count" id="profile-discord-username-count" data-profile-counter="discord_username"></span></label>
      <p class="minor field-help" id="profile-discord-help">Optional. Use your current 2-32 character Discord username. It is hidden unless you explicitly enable authenticated-member visibility below. Clearing it also turns visibility off.</p>
      <label class="settings-checkbox-row profile-visibility-row">
        <input name="discord_visible" type="checkbox" value="1">
        <span><strong>Show Discord username to authenticated profile viewers</strong><br>Unauthenticated visitors cannot open member profiles.</span>
      </label>
      <div class="account-profile-readonly">
        <div><span>Shareable member profile</span><a id="account-profile-share-link" href=""></a></div>
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
    <section class="account-delete-card" id="account-delete-card" aria-labelledby="account-delete-heading">
      <h2 id="account-delete-heading">Delete Account</h2>
      <div class="account-delete-warning" role="note">
        <strong>This permanently deletes your active account.</strong>
        <p>Your login, recovery, private profile, personal settings, sessions, and unshared personal media are removed or anonymized. Required chat, moderation, safety, audit, and shared-history records remain under <strong>[Deleted User]</strong>. This cannot be undone.</p>
      </div>
      <div id="account-delete-readiness" class="account-recovery-card" aria-live="polite">Checking account ownership and room responsibilities…</div>
      <div id="account-delete-owned-rooms" class="admin-scroll-list" aria-live="polite"></div>
      <form id="account-delete-form" class="shared-form compact-form" novalidate>
        <label id="account-delete-successor-label" hidden>Transfer all rooms I own to
          <select name="room_successor_user_id" aria-describedby="account-delete-successor-help">
            <option value="">Choose an eligible account</option>
          </select>
        </label>
        <p class="minor" id="account-delete-successor-help" hidden>Room history stays intact. The selected account becomes the owner of every room listed above.</p>
        <label>Current password
          <input name="current_password" type="password" required autocomplete="current-password">
        </label>
        <label>Type <strong>DELETE</strong> exactly
          <input name="confirmation" required autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="account-delete-confirmation-help">
        </label>
        <p class="minor" id="account-delete-confirmation-help">This confirmation is case-sensitive.</p>
        <button class="btn account-delete-submit" id="account-delete-submit" type="submit" disabled>Permanently Delete My Account</button>
      </form>
    </section>
  </section>
  <section class="shared-panel" data-account-panel="private-chat">
    <h2>Private Chat Protection</h2>
    <p class="minor">Trusted devices hold end-to-end encryption keys. The server and staff have no decryption backdoor. Device labels do not grant access.</p>
    <div class="account-recovery-card" id="account-private-chat-warning">
      If every trusted device and the Private Chat Recovery Phrase are lost, affected private-chat history cannot be recovered.
    </div>
    <div class="shared-form-actions">
      <button class="btn btn-primary" id="account-private-chat-device-create" type="button">Register This Device</button>
      <button class="btn" id="account-private-chat-recovery-create" type="button">Create or Replace Recovery Phrase</button>
    </div>
    <h3>Trusted and Pending Devices</h3>
    <div id="account-private-chat-devices" class="admin-scroll-list" aria-live="polite"></div>
    <h3>Private Chat Recovery Phrase</h3>
    <p class="minor">This is separate from your password and Lost Access recovery code. It is generated and encrypted in this browser. CoreChat never receives the phrase.</p>
    <output id="account-private-chat-recovery-output" class="account-recovery-card" aria-live="assertive">Recovery phrase hidden.</output>
    <p class="minor" id="account-private-chat-recovery-state">Checking recovery configuration…</p>
  </section>
  <section class="shared-panel" data-account-panel="voice-webcam"<?= $voiceWebcamAvailable ? '' : ' hidden' ?>>
    <div class="account-preference-heading">
      <h2>Voice &amp; Webcam</h2>
      <button class="settings-entry-info" type="button" data-account-info="voice-webcam-overview" title="More information about Voice &amp; Webcam" aria-label="More information about Voice &amp; Webcam" aria-controls="account-voice-webcam-overview-help" aria-expanded="false"><span class="settings-entry-info-glyph" aria-hidden="true">i</span></button>
    </div>
    <div class="settings-entry-help-panel account-preference-help" id="account-voice-webcam-overview-help" tabindex="-1" hidden>
      <p>Installation policy controls which personal options are available. A hidden option keeps its saved value and returns unchanged if an administrator enables it again.</p>
    </div>
    <p class="minor">Choose how you transmit voice and who can see your webcam.</p>
    <form id="account-voice-webcam-form" class="shared-form">
      <fieldset id="account-transmission-mode-fields"<?= $voiceTransmissionAvailable ? '' : ' hidden' ?>>
        <legend class="account-preference-legend"><span>Voice transmission</span><button class="settings-entry-info" type="button" data-account-info="voice-transmission" title="More information about Voice transmission" aria-label="More information about Voice transmission" aria-controls="account-transmission-help" aria-expanded="false"><span class="settings-entry-info-glyph" aria-hidden="true">i</span></button></legend>
        <div class="settings-entry-help-panel account-preference-help" id="account-transmission-help" tabindex="-1" hidden>
          <p>Your saved mode and muted-on-join choice remain unchanged while this option is unavailable. The hold key is private to this browser, works while the page is focused, and uses the existing live-voice mute controls.</p>
        </div>
        <label>Transmission mode
          <select name="transmission_mode">
            <option value="voice-activation">Voice activation</option>
            <option value="push-to-talk">Push to talk</option>
            <option value="push-to-mute">Push to mute</option>
          </select>
        </label>
        <label class="account-preference-checkbox-row">
          <input name="always_muted_on_join" type="checkbox" value="1">
          <span><strong>Always muted on join</strong></span>
        </label>
        <label>Device-local hold key
          <span class="account-binding-row"><input name="transmission_binding" readonly value="Unassigned"><button class="btn" id="account-binding-set" type="button">Set key</button><button class="btn" id="account-binding-clear" type="button">Clear</button></span>
        </label>
      </fieldset>
      <fieldset id="account-webcam-audience-fields"<?= $webcamAudienceAvailable ? '' : ' hidden' ?>>
        <legend class="account-preference-legend"><span>Webcam audience</span><button class="settings-entry-info" type="button" data-account-info="webcam-audience" title="More information about Webcam audience" aria-label="More information about Webcam audience" aria-controls="account-webcam-audience-help" aria-expanded="false"><span class="settings-entry-info-glyph" aria-hidden="true">i</span></button></legend>
        <div class="settings-entry-help-panel account-preference-help" id="account-webcam-audience-help" tabindex="-1" hidden>
          <p>This choice limits live webcam-track delivery. People outside the selected audience see your saved avatar instead. Your choice remains saved while this option is unavailable.</p>
        </div>
        <label>Who can see my webcam?
          <select name="webcam_audience_mode">
            <option value="everyone">Everyone in the room</option>
            <option value="private-voice">Members of my current private voice chat</option>
            <option value="selected">Only selected people</option>
            <option value="nobody">Nobody — local preview only</option>
          </select>
        </label>
      </fieldset>
      <div class="shared-form-actions">
        <button class="btn btn-primary" type="submit">Save Voice &amp; Webcam</button>
        <button class="btn" id="account-voice-webcam-reset" type="button">Restore Saved Values</button>
      </div>
    </form>
  </section>
  <section class="shared-panel" data-account-panel="status">
    <h2>Account Status</h2>
    <dl class="account-status-list" id="account-status-list"></dl>
    <h2>Current Capabilities</h2>
    <div class="capability-list" id="account-capabilities"></div>
  </section>
  <section class="shared-panel" data-account-panel="requests">
    <h2>Requests, Appeals, and Notices</h2>
    <p class="minor">Requests do not grant access automatically. Current restrictions and suspensions remain enforced while a request or appeal is reviewed.</p>
    <div id="account-request-explanation" class="account-recovery-card"></div>
    <form id="account-trusted-review-form" class="shared-form compact-form">
      <h3>Request Trusted Review</h3>
      <label>Private note <textarea name="note" rows="4" maxlength="2000" aria-describedby="trusted-review-note-help"></textarea></label>
      <p class="minor" id="trusted-review-note-help">Optional. Up to 2,000 characters; visible only to authorized reviewers.</p>
      <button class="btn btn-primary" type="submit">Request Trusted Review</button>
    </form>
    <form id="account-capability-request-form" class="shared-form">
      <h3>Request Capabilities</h3>
      <div class="shared-form-actions">
        <button class="btn" id="account-capability-select-all" type="button">Select all available</button>
        <button class="btn" id="account-capability-clear" type="button">Clear selection</button>
      </div>
      <div id="account-capability-request-options" class="capability-list"></div>
      <label>Private note <textarea name="note" rows="4" maxlength="2000"></textarea></label>
      <button class="btn btn-primary" type="submit">Submit Capability Request</button>
    </form>
    <form id="account-appeal-form" class="shared-form compact-form">
      <h3>Appeal Restriction or Suspension</h3>
      <label>Private appeal <textarea name="note" rows="5" maxlength="2000" required></textarea></label>
      <button class="btn btn-primary" type="submit">Submit Appeal</button>
    </form>
    <h3>Request and Appeal Status</h3>
    <div id="account-cases" class="admin-scroll-list"></div>
    <h3>Notices</h3>
    <div id="account-notices" class="admin-scroll-list"></div>
  </section>
  <section class="shared-panel" data-account-panel="safety">
    <h2>Report, Mute, and Block</h2>
    <p class="minor">Reporting never punishes automatically. The reported person is not told who submitted a report. Mute is private and reversible; Block is stronger and server-enforced.</p>
    <form id="account-report-form" class="shared-form">
      <h3>Report</h3>
      <label>Origin
        <select name="origin_type" required>
          <option value="user">User</option><option value="profile">Profile</option>
          <option value="avatar">Avatar</option><option value="message">Message</option>
          <option value="room">Room</option><option value="community">Community</option>
          <option value="dm">Direct message</option><option value="relationship">Relationship</option>
          <option value="game">Game</option><option value="gesture">Gesture</option>
          <option value="media">Media</option><option value="file">File offer</option>
          <option value="website-room">Website room</option>
        </select>
      </label>
      <label>Exact item or user reference <input name="origin_reference" maxlength="191" required></label>
      <label>Reported user ID (optional) <input name="reported_user_id" type="number" min="1"></label>
      <label>Reason <textarea name="reason" rows="5" maxlength="2000" required></textarea></label>
      <button class="btn btn-primary" type="submit">Submit Report</button>
    </form>
    <form id="account-mute-form" class="shared-form compact-form">
      <h3>Mute User</h3>
      <label>User ID <input name="target_user_id" type="number" min="1" required></label>
      <label>Duration
        <select name="duration"><option value="until-unmute">Until I unmute</option><option value="1-hour">1 hour</option><option value="24-hours">24 hours</option></select>
      </label>
      <button class="btn btn-primary" type="submit">Mute Privately</button>
    </form>
    <h3>Muted Users</h3>
    <div id="account-muted-users" class="admin-scroll-list"></div>
    <h3>My Reports</h3>
    <div id="account-reports" class="admin-scroll-list"></div>
  </section>
</main>
<script src="<?= e($assetVersion('/assets/js/account.js')) ?>"></script>
</body>
</html>
