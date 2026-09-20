<?php
require_once __DIR__ . '/includes/base.php';
require_once __DIR__ . '/includes/inner_tranquillity_player_capability.php';
require_once __DIR__ . '/includes/runtime_diagnostics_capability.php';
$user = require_user();
$pdo = db();
$branding = private_site_branding_projection($pdo, 'room');
$communityEjection = active_community_ejection($pdo, (int)$user['id']);
if ($communityEjection) {
    redirect_to('/community_ejected.php');
}
$roomKey = trim((string)($_GET['id'] ?? ''));
$stmt = $pdo->prepare('SELECT r.*, u.display_name AS owner_name FROM rooms r JOIN users u ON u.id = r.owner_id WHERE r.id = ? LIMIT 1');
$stmt->execute([ctype_digit($roomKey) ? (int)$roomKey : 0]);
$room = $stmt->fetch();
if (!$room && $roomKey !== '') {
    $stmt = $pdo->prepare('SELECT r.*, u.display_name AS owner_name FROM rooms r JOIN users u ON u.id = r.owner_id WHERE r.public_id = ? LIMIT 1');
    $stmt->execute([$roomKey]);
    $room = $stmt->fetch();
}
if (!$room) {
    redirect_to('/lobby.php');
}
$roomId = (int)$room['id'];
$liveWebsiteFrameStatement = $pdo->prepare('SELECT target_url FROM live_website_rooms WHERE room_id = ? LIMIT 1');
$liveWebsiteFrameStatement->execute([$roomId]);
$liveWebsiteFrameUrl = $liveWebsiteFrameStatement->fetchColumn();
$isLiveWebsiteRoom = is_string($liveWebsiteFrameUrl) && trim($liveWebsiteFrameUrl) !== '';
if ($isLiveWebsiteRoom) {
    // Evaluate an old temporary room before this request revives its participant.
    // A room that has already been empty beyond its grace period must return to
    // the lobby rather than silently becoming permanent when its URL is revisited.
    live_website_rooms_cleanup($pdo);
    $liveWebsiteFrameStatement->execute([$roomId]);
    $liveWebsiteFrameUrl = $liveWebsiteFrameStatement->fetchColumn();
    if (!is_string($liveWebsiteFrameUrl) || trim($liveWebsiteFrameUrl) === '') {
        redirect_to('/lobby.php');
    }
    $liveWebsiteFrameUrl = trim($liveWebsiteFrameUrl);
    security_send_content_security_policy([$liveWebsiteFrameUrl]);
} else {
    $liveWebsiteFrameUrl = '';
}
$isInstallationOwner = moderation_identity_is_owner($pdo, (int)$user['id']);
$roomMaintenanceWarnings = [];
$activeEjection = active_room_ejection($pdo, $roomId, (int)$user['id']);
if ($activeEjection) {
    $_SESSION['room_ejection_notice'] = [
        'permanent' => (bool)$activeEjection['permanent'],
        'duration_minutes' => $activeEjection['duration_minutes'] !== null ? (int)$activeEjection['duration_minutes'] : null,
    ];
    redirect_to('/lobby.php');
}
if (!room_access_allowed($room, $user)) {
    require __DIR__ . '/includes/room_access_entry.php';
    exit;
}
$csrfToken = csrf_token();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$session = active_session_for_room($pdo, $roomId);
$participant = participant_for_user($pdo, (int)$session['id'], $user);
try {
    avatar_relationship_transaction($pdo, static function() use ($pdo, $participant, $user): array {
        $cancelledLapAnimations = avatar_relationship_cancel_active_lap_animations(
            $pdo,
            (int)$user['id'],
            'participant-room-rejoin'
        );
        $pdo->prepare('UPDATE participants SET webcam_path = NULL, webcam_enabled = 0 WHERE id = ?')
            ->execute([(int)$participant['id']]);
        return ['cancelled_lap_animations' => $cancelledLapAnimations];
    });
} catch (Throwable $error) {
    if (!db_is_transient_lock_error($error)) throw $error;
    $reference = strtoupper(substr(hash(
        'sha256',
        'participant-room-rejoin|' . get_class($error) . '|' . $error->getMessage()
    ), 0, 16));
    error_log(sprintf(
        'chatroom participant rejoin cleanup deferred [%s] room=%d session=%d user=%d: %s',
        $reference,
        $roomId,
        (int)$session['id'],
        (int)$user['id'],
        $error->getMessage()
    ));
    $roomMaintenanceWarnings[] = 'participant-room-rejoin: ' . $reference;
}
touch_participant_presence($pdo, $participant, 1);
try {
    $runtimeMaintenanceWarnings = [];
    runtime_maintenance_for_session($pdo, (int)$session['id'], $runtimeMaintenanceWarnings);
    foreach ($runtimeMaintenanceWarnings as $warning) {
        if (!is_array($warning) || empty($warning['reference'])) continue;
        $roomMaintenanceWarnings[] = (string)($warning['phase'] ?? 'maintenance')
            . ': ' . (string)$warning['reference'];
    }
} catch (Throwable $error) {
    $reference = strtoupper(substr(hash(
        'sha256',
        'room-runtime-maintenance|' . get_class($error) . '|' . $error->getMessage()
    ), 0, 16));
    error_log(sprintf(
        'chatroom runtime maintenance failure [%s] room=%d session=%d user=%d %s: %s',
        $reference,
        $roomId,
        (int)$session['id'],
        (int)$user['id'],
        get_class($error),
        $error->getMessage()
    ));
    $roomMaintenanceWarnings[] = $reference;
}
$participant['webcam_path'] = null;
$participant['webcam_enabled'] = 0;

emit_event($pdo, (int)$session['id'], 'participant_join', array_merge([
    'id' => (int)$participant['id'],
    'user_id' => (int)$user['id'],
    'username' => (string)$user['username'],
    'display_name' => $participant['display_name'],
    'role' => $user['role'] ?? 'user',
    'is_owner' => (int)$room['owner_id'] === (int)$user['id'],
    'avatar_path' => $participant['avatar_path'],
    'avatar_url' => resolve_avatar($participant['avatar_path']),
    'avatar_source_width_px' => max(1, (int)($participant['avatar_source_width_px'] ?? 150)),
    'avatar_source_height_px' => max(1, (int)($participant['avatar_source_height_px'] ?? 150)),
    'avatar_orientation' => avatar_orientation_normalize($participant['avatar_orientation'] ?? null),
    'avatar_orientation_version' => max(1, (int)($participant['avatar_orientation_version'] ?? 1)),
    'aura_effect' => $participant['aura_effect'] ?? null,
    'position_x' => (float)$participant['position_x'],
    'position_y' => (float)$participant['position_y'],
    'webcam_path' => $participant['webcam_path'],
    'webcam_enabled' => !empty($participant['webcam_enabled']),
    'linked_to' => $participant['linked_to_participant_id'] ? (int)$participant['linked_to_participant_id'] : null,
    'link_mode' => in_array(($participant['link_mode'] ?? 'normal'), ['normal', 'lap'], true) ? $participant['link_mode'] : 'normal',
    'joined_at' => gmdate('Y-m-d H:i:s'),
], avatar_size_participant_event_fields($pdo, $participant)));
$lastEventId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM events WHERE session_id = ' . (int)$session['id'])->fetchColumn();
$linkIconCatalog = link_icon_catalog($pdo);
$innerTranquillityPlayer = inner_tranquillity_player_capability($room);
$runtimeDiagnostics = runtime_diagnostics_capability();
$roleColors = role_color_settings($pdo);
$gestureMakerExtension = first_party_extension_status($pdo, 'gesture-maker');
$gestureMakerAvailable = ($gestureMakerExtension['state'] ?? '') === 'enabled';
$canvasExtension = first_party_extension_status($pdo, 'canvas');
$canvasAvailable = ($canvasExtension['state'] ?? '') === 'enabled';
$roomAssetVersion = static function (string $path): string {
    $absolutePath = __DIR__ . $path;
    $version = is_file($absolutePath) ? (string)filemtime($absolutePath) : (string)time();
    return app_url($path) . '?v=' . rawurlencode($version);
};

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(branded_page_title((string)$room['name'], $pdo, 'room')) ?></title>
  <link rel="stylesheet" href="<?= e($roomAssetVersion('/assets/css/styles.css')) ?>">
  <link rel="stylesheet" href="<?= e($roomAssetVersion('/assets/css/live-website-rooms.css')) ?>">
  <?php if ($canvasAvailable): ?><link rel="stylesheet" href="<?= e($roomAssetVersion('/extensions/canvas/assets/canvas.css')) ?>"><?php endif; ?>
  <?php if ($innerTranquillityPlayer['available']): ?>
  <link rel="stylesheet" href="<?= e($innerTranquillityPlayer['assets']['css']) ?>">
  <?php endif; ?>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/avatar-library.css?v=20260914-movable')) ?>">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/popup-behavior.css?v=20260916-r1')) ?>">
</head>
<body data-room-id="<?= e($room['public_id']) ?>" data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e($csrfToken) ?>" data-branding-name="<?= e($branding['effective_name']) ?>" data-role-colors-mode="<?= e($roleColors['mode']) ?>" style="<?= e(role_color_css_variables($pdo)) ?>" data-inner-tranquillity-player-relevant="<?= $innerTranquillityPlayer['relevant'] ? 'true' : 'false' ?>" data-inner-tranquillity-player-available="<?= $innerTranquillityPlayer['available'] ? 'true' : 'false' ?>" data-inner-tranquillity-player-reason="<?= e($innerTranquillityPlayer['reason']) ?>" data-runtime-diagnostics-enabled="<?= $runtimeDiagnostics['enabled'] ? 'true' : 'false' ?>" data-runtime-diagnostics-mode="<?= e($runtimeDiagnostics['mode']) ?>" data-runtime-verification-controls="<?= $runtimeDiagnostics['verification_controls'] ? 'true' : 'false' ?>">
<div class="room-layout">
  <div class="version-banner" id="version-banner" hidden>
    <span id="version-banner-text">A new ChatSpace version is available.</span>
    <button class="btn btn-aqua" id="version-refresh" type="button">Refresh</button>
  </div>
  <main class="main">
    <?php if ($isInstallationOwner && $roomMaintenanceWarnings): ?>
    <div class="form-error" role="alert">Background room maintenance could not complete. Error reference: <?= e(implode(', ', $roomMaintenanceWarnings)) ?></div>
    <?php endif; ?>
    <section class="room-stage" id="room-stage" tabindex="0" aria-label="Room stage; scroll to view larger avatar relationships">
      <div class="room-stage-viewport" id="room-stage-viewport">
        <?php $roomBgTiled = !empty($room['import_url']) && !empty($room['background_path']) && !str_starts_with((string)$room['background_mime'], 'video/'); ?>
        <div class="room-bg<?= $roomBgTiled ? ' room-bg-tiled' : '' ?>" <?php if ($room['background_path'] && !str_starts_with((string)$room['background_mime'], 'video/')): ?>style="background-image:url('<?= e(media_url($room['background_path'])) ?>')"<?php endif; ?>>
          <?php if ($room['background_path'] && str_starts_with((string)$room['background_mime'], 'video/')): ?>
          <video class="smart-bg-video" autoplay muted playsinline preload="auto"><source src="<?= e(media_url($room['background_path'])) ?>" type="<?= e($room['background_mime']) ?>"></video>
          <?php endif; ?>
        </div>
        <div class="vp-room-layout" id="vp-room-layout" hidden></div>
        <?php if ($isLiveWebsiteRoom): ?>
        <section class="live-website-room-layer" id="live-website-room-layer" aria-label="Live Website Room" hidden>
          <iframe id="live-website-room-frame" title="Live website" src="<?= e($liveWebsiteFrameUrl) ?>" sandbox="allow-downloads allow-forms allow-modals allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts" allow="autoplay; clipboard-read; clipboard-write; fullscreen" referrerpolicy="strict-origin-when-cross-origin"></iframe>
          <div class="live-website-navigation-choice" id="live-website-navigation-choice" role="dialog" aria-modal="true" aria-labelledby="live-website-navigation-title" hidden>
            <div class="live-website-navigation-card">
              <span class="live-website-room-kicker">The room creator navigated</span>
              <h2 id="live-website-navigation-title">Follow or stay?</h2>
              <p id="live-website-navigation-copy"></p>
              <div class="live-website-navigation-actions">
                <button class="btn btn-primary" id="live-website-navigation-follow" type="button">Follow</button>
                <button class="btn" id="live-website-navigation-stay" type="button">Stay Here</button>
              </div>
              <div class="live-website-room-status" id="live-website-navigation-status" role="status" aria-live="polite"></div>
            </div>
          </div>
        </section>
        <?php endif; ?>
        <div class="game-stage-layer" id="game-stage" hidden>
        <div class="game-stage-body" id="game-stage-body">
          <div class="game-player-rail" aria-label="Players 1 and 3">
            <aside class="game-player-card" id="game-player-one" data-game-seat="1">
              <img src="<?= e(app_url('/assets/images/baghead.png')) ?>" alt="">
              <strong>Waiting</strong><span class="minor">Player 1</span><span class="game-typing-pill">typing...</span>
            </aside>
            <aside class="game-player-card" id="game-player-three" data-game-seat="3" hidden>
              <img src="<?= e(app_url('/assets/images/baghead.png')) ?>" alt="">
              <strong>Waiting</strong><span class="minor">Player 3</span><span class="game-typing-pill">typing...</span>
            </aside>
          </div>
          <div class="game-frame-wrap">
            <section class="game-session-summary" id="game-session-summary" aria-label="Game session details">
              <div class="game-session-facts" id="game-session-facts"></div>
              <section class="game-pregame-rules" id="game-pregame-rules" aria-labelledby="game-pregame-rules-title" hidden>
                <div class="game-pregame-rules-heading">
                  <strong id="game-pregame-rules-title">Game Rules</strong>
                  <span class="game-rule-classification" id="game-rule-classification"></span>
                </div>
                <p class="minor" id="game-pregame-rules-description"></p>
                <div class="game-pregame-rule-controls" id="game-pregame-rule-controls"></div>
                <button class="btn" id="game-pregame-rules-save" type="button" hidden>Update Game Rules</button>
                <div class="minor" id="game-pregame-rules-status" role="status" aria-live="polite"></div>
              </section>
              <div class="game-session-notice" id="game-session-notice" role="status" aria-live="polite"></div>
              <div class="game-session-actions" id="game-session-actions">
                <button class="btn" id="game-return-chat" type="button">Room Chat</button>
                <button class="btn" id="game-pause" type="button" hidden>Pause game</button>
                <button class="btn btn-primary" id="game-accept" type="button" hidden>Accept Game</button>
                <button class="btn" id="game-rematch" type="button">Play Again</button>
                <button class="btn btn-danger" id="game-resign" type="button">Resign</button>
                <button class="btn btn-danger" id="game-close" type="button">Exit Game</button>
                <button class="btn" id="game-save" type="button">Save Game</button>
                <button class="btn" id="game-resume" type="button">Resume Saved Game</button>
                <button class="btn" id="game-vote-continue" type="button">Vote to Continue</button>
                <button class="btn" id="game-vote-save" type="button">Vote to Save</button>
                <button class="btn" id="game-vote-end" type="button">Vote to End</button>
                <button class="btn" id="game-records" type="button">Game Records</button>
              </div>
              <div class="game-presentation-actions" id="game-presentation-actions" role="group" aria-label="Game presentation controls" hidden>
                <button class="btn" id="game-sfx-toggle" type="button" hidden>SFX On</button>
                <button class="btn" id="game-gfx-toggle" type="button" hidden>GFX On</button>
                <button class="btn" id="game-music-toggle" type="button" hidden>Music Off</button>
                <button class="btn" id="game-webcams-toggle" type="button" aria-controls="game-webcams-panel" aria-expanded="false" hidden>Webcams</button>
              </div>
              <div class="game-secondary-actions game-pause-followup-actions" id="game-pause-followup-actions" role="group" aria-label="Pause and reconnect actions" hidden></div>
              <div class="game-secondary-actions game-terminal-actions" id="game-terminal-actions" role="group" aria-label="Game continuation and replay actions" hidden></div>
            </section>
            <iframe id="game-frame" title="Game"></iframe>
            <section class="game-webcams-panel" id="game-webcams-panel" aria-label="In-game webcam controls" hidden></section>
            <div class="game-webcam-layer" id="game-webcam-layer" aria-label="Movable in-game webcams" hidden></div>
          </div>
          <div class="game-player-rail" aria-label="Players 2 and 4">
            <aside class="game-player-card" id="game-player-two" data-game-seat="2">
              <img src="<?= e(app_url('/assets/images/baghead.png')) ?>" alt="">
              <strong>Waiting</strong><span class="minor">Player 2</span><span class="game-typing-pill">typing...</span>
            </aside>
            <aside class="game-player-card" id="game-player-four" data-game-seat="4" hidden>
              <img src="<?= e(app_url('/assets/images/baghead.png')) ?>" alt="">
              <strong>Waiting</strong><span class="minor">Player 4</span><span class="game-typing-pill">typing...</span>
            </aside>
          </div>
        </div>
      </div>
      </div>
      <div class="avatar-viewport-layer" id="avatar-viewport-layer"></div>
      <div class="relationship-canvas" id="relationship-canvas"></div>
    </section>
    <div class="divider" id="horizontal-divider"></div>
    <section class="chat-pane">
      <div class="messages" id="messages"></div>
      <div class="reply-draft" id="reply-draft" hidden>
        <div>
          <strong id="reply-draft-author">Replying to someone</strong>
          <span id="reply-draft-preview"></span>
        </div>
        <button id="reply-draft-cancel" type="button" aria-label="Cancel reply">×</button>
      </div>
      <form class="composer" id="composer">
        <button class="composer-icon-btn" id="attach-btn" type="button" aria-label="Add attachment"><img src="<?= e(app_url('/assets/images/input-add.png')) ?>" alt=""></button>
        <div class="attach-menu" id="attach-menu" hidden>
          <button type="button" id="attach-file-btn">Attach File</button>
          <button type="button" id="attach-voice-btn">Attach Voice Note</button>
          <button type="button" id="show-shared-attachments-btn">Show Shared Attachments</button>
        </div>
        <input class="hidden-file-input" id="chat-file-input" type="file" accept="image/*,.pdf,.doc,.docx,.rtf,.txt,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,application/rtf">
        <textarea id="chat-input" maxlength="1000" rows="1" autocomplete="off" placeholder="Message <?= e($room['name']) ?>"></textarea>
        <div class="composer-actions">
          <label class="important-message-control" id="important-message-control" hidden title="Force this Owner or Administrator announcement to use the configured role color for every viewer.">
            <input id="important-message-toggle" type="checkbox">
            <span>Important</span>
          </label>
          <span class="char-counter" id="char-counter">0/1000</span>
          <button class="composer-icon-btn emoji-btn" id="emoji-btn" type="button" aria-label="Media palette"><img src="<?= e(app_url('/assets/images/input-emoji.png')) ?>" alt=""></button>
          <button class="send-btn" type="submit" aria-label="Send message"><img src="<?= e(app_url('/assets/images/input-send.png')) ?>" alt=""></button>
        </div>
      </form>
      <div class="chat-tabs" id="chat-tabs">
        <button class="chat-tab active" type="button" data-chat-tab="room"><img src="<?= e(app_url('/assets/images/chat-pane-bubble.png')) ?>" alt=""> <span>Chat Room</span><span class="tab-badge" hidden>0</span></button>
        <button class="chat-tab" type="button" data-chat-tab="community"><img src="<?= e(app_url('/assets/images/chat-pane-community.png')) ?>" alt=""> <span>Community Chat</span><span class="tab-badge" hidden>0</span></button>
        <span id="link-tabs"></span>
        <?php if ($canvasAvailable): ?><button class="chat-tab" type="button" data-canvas-launcher data-canvas-scope="room" data-canvas-room="<?= e($room['public_id']) ?>"><span>Canvas</span></button><?php endif; ?>
        <button class="chat-tab transfers-tab-button" id="transfers-button" type="button" aria-controls="transfers-tray" aria-expanded="false"><span>Transfers</span><span class="tab-badge" id="transfers-count" aria-hidden="true" hidden>0</span></button>
      </div>
    </section>
  </main>
  <div class="vertical-divider" id="vertical-divider"></div>
  <aside class="sidebar">
    <section class="side-section">
      <button class="gear-btn" id="room-menu-btn" type="button" aria-label="Room menu">⚙</button>
      <div class="side-title">Room</div>
      <div class="room-title-row">
        <strong id="room-title-text"><?= e($room['name']) ?></strong>
        <button class="room-action-btn" id="room-action-btn" type="button" aria-label="Room actions"<?= can_use_host_tools($user, $room) ? '' : ' hidden' ?>>•••</button>
      </div>
      <div class="minor">Created by <?= e($room['owner_name']) ?></div>
      <span id="live-website-room-domain" hidden></span>
      <form class="live-website-room-owner-controls" id="live-website-room-owner-controls" hidden>
        <input id="live-website-room-url" type="url" inputmode="url" autocomplete="url" placeholder="https://example.com" aria-label="Website URL">
        <button class="btn btn-primary" id="live-website-room-go" type="submit">Go Together</button>
      </form>
      <div class="live-website-room-status" id="live-website-room-status" role="status" aria-live="polite"></div>
      <div class="vp-music-player" id="vp-music-player" hidden>
        <div class="side-title">Room Audio</div>
        <select id="vp-music-select" hidden></select>


        <audio id="vp-music-audio" controls preload="none"></audio>

        <div class="vp-music-youtube-controls" id="vp-music-youtube-controls" hidden>
          <div class="vp-music-youtube-title">YouTube</div>
          <div class="vp-music-youtube-actions">
            <button
              class="btn btn-primary vp-music-launch"
              id="vp-music-launch"
              type="button"
              title="Open YouTube in the pop-out player"
              aria-controls="vp-music-modal"
              aria-expanded="false">
              Pop out
            </button>
            <button
              class="btn btn-primary vp-music-launch"
              id="vp-music-embed"
              type="button"
              title="Play YouTube in the room panel"
              aria-controls="vp-music-youtube"
              aria-expanded="false">
              Play here
            </button>
          </div>
        </div>

<div id="vp-music-youtube" hidden></div>



      </div>
    </section>
    <section class="side-section games-side-section">
      <div class="side-title">Games</div>
      <div class="game-start-wrap">
        <button class="btn icon-label game-start-btn" id="game-start-btn" type="button" aria-haspopup="dialog" aria-controls="game-start-menu" aria-expanded="false"><img src="<?= e(app_url('/assets/images/games-icon.png')) ?>" alt="">Start a Game</button>
        <button class="btn icon-label room-games-btn" id="room-games-btn" type="button" aria-haspopup="dialog" aria-controls="room-games-menu" aria-expanded="false"><span class="room-games-list-icon" aria-hidden="true"><i></i><i></i><i></i></span>Room Games</button>
      </div>
      <div class="game-list" id="active-games"></div>
    </section>
    <section class="side-section">
      <div class="side-title">Chatting <span id="participant-count-label">(0)</span></div>
      <button class="btn relationship-manage-launcher" id="relationship-manage-btn" type="button" hidden>Manage Relationship</button>
      <div class="user-list" id="user-list"></div>
    </section>
    <div class="sidebar-bottom-tools">
      <section class="side-section voice-side-section" id="voice-side-section">
        <div class="side-title" id="voice-title" hidden>Voice Chat <span id="voice-count-label"></span></div>
        <div class="voice-list" id="voice-list" hidden></div>
        <div class="voice-primary-actions">
          <button class="btn btn-voice" id="voice-toggle" type="button">Join Voice</button>
          <button class="btn btn-camera" id="webcam-toggle" type="button" aria-pressed="false">Start Camera</button>
        </div>
        <button class="btn btn-voice-hold" id="voice-transmission-hold" type="button" hidden aria-pressed="false">Hold to talk</button>
        <div class="minor" id="voice-transmission-status" hidden></div>
        <button class="btn btn-voice" id="private-voice-open" type="button" hidden>Private Voice</button>
      </section>
      <section class="side-section">
        <button class="btn icon-label" id="locate-btn" style="width:100%;"><img src="<?= e(app_url('/assets/images/locate.png')) ?>" alt="">Locate Friends</button>
        <div class="sidebar-status-line"><span class="app-version" id="app-version">Checking version...</span><span class="latency-monitor" id="latency-monitor">Latency --ms</span></div>
      </section>
    </div>
  </aside>
</div>
<div class="modal floating-modal" id="vp-music-modal">
  <div class="modal-box vp-music-modal-box">
    <div class="modal-head vp-music-drag-handle" id="vp-music-drag-handle">
      <strong id="vp-music-modal-title">Room Music</strong>
      <div class="vp-music-window-actions">
        <button class="window-minimize" id="vp-music-modal-minimize" type="button" aria-label="Minimize">−</button>
      <button class="window-close" id="vp-music-modal-close" type="button" aria-label="Close">×</button>
      </div>
    </div>
    <div class="vp-music-frame-wrap" id="vp-music-frame-wrap"></div>
  </div>
</div>
<div class="modal" id="voice-device-modal">
  <form class="modal-box voice-device-box" id="voice-device-form">
    <div class="modal-head">
      <strong>Join Voice</strong>
      <button class="window-close" id="voice-device-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="voice-device-grid">
      <label>Microphone
        <select id="voice-input-device"></select>
      </label>
      <label>Speaker
        <select id="voice-output-device"></select>
      </label>
    </div>
    <div class="minor" id="voice-device-note">Choose your audio devices before joining voice.</div>
    <div class="voice-device-actions" style="flex-wrap:wrap">
      <button class="btn btn-primary" id="voice-device-join" type="submit" style="flex:1 1 118px;min-width:0">Join Voice</button>
      <button class="btn" id="voice-device-refresh" type="button" style="flex:1 1 168px;min-width:0">Allow microphone &amp; refresh</button>
      <button class="btn" id="voice-device-cancel" type="button" style="flex:1 1 118px;min-width:0">Cancel</button>
    </div>
    <div class="admin-form-status" id="voice-device-status" aria-live="polite"></div>
  </form>
</div>
<div class="modal" id="private-voice-modal" role="dialog" aria-modal="true" aria-labelledby="private-voice-title">
  <div class="modal-box private-voice-box">
    <div class="modal-head">
      <strong id="private-voice-title">Private Voice Chats</strong>
      <button class="window-close" id="private-voice-close" type="button" aria-label="Close Private Voice Chats">&times;</button>
    </div>
    <p class="minor" id="private-voice-policy-note"></p>
    <div id="private-voice-content"></div>
    <div class="admin-form-status" id="private-voice-status" aria-live="polite"></div>
  </div>
</div>
<div class="modal" id="webcam-audience-modal" role="dialog" aria-modal="true" aria-labelledby="webcam-audience-title">
  <form class="modal-box private-voice-box" id="webcam-audience-form">
    <div class="modal-head">
      <strong id="webcam-audience-title">Who can see my webcam?</strong>
      <button class="window-close" id="webcam-audience-close" type="button" aria-label="Cancel webcam audience selection">&times;</button>
    </div>
    <p class="minor">Until you confirm, no remote participant receives your live webcam. Anyone outside the confirmed audience sees your saved avatar.</p>
    <label class="settings-checkbox-row"><input type="radio" name="audience_mode" value="everyone"><span>Everyone in the room</span></label>
    <label class="settings-checkbox-row"><input type="radio" name="audience_mode" value="private-voice"><span>Members of my current private voice chat</span></label>
    <label class="settings-checkbox-row"><input type="radio" name="audience_mode" value="selected"><span>Only selected people</span></label>
    <label class="settings-checkbox-row"><input type="radio" name="audience_mode" value="nobody"><span>Nobody — local preview only</span></label>
    <fieldset id="webcam-audience-people" hidden>
      <legend>Select current room members</legend>
      <div class="capability-list" id="webcam-audience-person-list"></div>
    </fieldset>
    <div class="shared-form-actions">
      <button class="btn btn-primary" type="submit">Confirm Audience</button>
      <button class="btn" id="webcam-audience-cancel" type="button">Cancel</button>
    </div>
    <div class="admin-form-status" id="webcam-audience-status" aria-live="polite"></div>
  </form>
</div>
<div class="modal" id="message-protection-dialog" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="message-protection-dialog-title" aria-describedby="message-protection-dialog-intro">
  <form class="modal-box message-protection-dialog-box" id="message-protection-form" novalidate>
    <div class="modal-head">
      <strong id="message-protection-dialog-title">Change Message Protection</strong>
    </div>
    <p class="minor" id="message-protection-dialog-intro">Choose how new messages in this conversation are protected. Earlier messages keep their existing protection unless a verified conversion is available.</p>
    <fieldset class="message-protection-choices" id="message-protection-choices">
      <legend>Message protection</legend>
      <label class="message-protection-choice">
        <input type="radio" name="message_protection_mode" value="standard">
        <span><strong>Standard</strong><small>Messages use the conversation's normal access controls.</small></span>
      </label>
      <label class="message-protection-choice">
        <input type="radio" name="message_protection_mode" value="server-encrypted">
        <span><strong>Encrypted on this server</strong><small>Messages are encrypted while stored and remain available to the server for delivery and approved operations.</small></span>
      </label>
      <label class="message-protection-choice">
        <input type="radio" name="message_protection_mode" value="e2ee-private">
        <span><strong>End-to-end encrypted</strong><small>New private messages are readable only on participants' trusted devices.</small></span>
      </label>
    </fieldset>
    <div class="message-protection-availability minor" id="message-protection-availability" role="note" hidden></div>
    <label class="message-protection-note" for="message-protection-note">
      Private note
      <textarea id="message-protection-note" maxlength="500" rows="4" required aria-describedby="message-protection-note-help"></textarea>
    </label>
    <p class="minor" id="message-protection-note-help">Briefly explain why you are making this change. Participants never see this note. Only a one-way fingerprint is kept to verify that a note was supplied; the note itself is not stored or shared.</p>
    <div class="message-protection-impact minor" id="message-protection-impact" role="note"></div>
    <label class="message-protection-confirmation" id="message-protection-e2ee-confirmation" hidden>
      <input type="checkbox" id="message-protection-e2ee-confirm">
      <span>I understand that End-to-End Encryption protects new messages only, earlier messages keep their existing protection, and losing all trusted devices and my Private Chat Recovery Phrase may make encrypted history unrecoverable.</span>
    </label>
    <div class="admin-form-status" id="message-protection-status" role="status" aria-live="polite" tabindex="-1"></div>
    <div class="shared-form-actions message-protection-actions">
      <button class="btn" id="message-protection-cancel" type="button">Cancel</button>
      <button class="btn btn-primary" id="message-protection-submit" type="submit">Change Message Protection</button>
    </div>
  </form>
</div>
<div class="modal" id="message-protection-auth-dialog" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="message-protection-auth-title" aria-describedby="message-protection-auth-copy">
  <div class="modal-box message-protection-auth-box">
    <div class="modal-head">
      <strong id="message-protection-auth-title">Authentication needed</strong>
    </div>
    <p class="minor" id="message-protection-auth-copy">Open Security &amp; Privacy to sign in again or finish the account security setup required before changing message protection.</p>
    <div class="shared-form-actions message-protection-actions">
      <a class="btn btn-primary" id="message-protection-auth-open" href="<?= e(app_url('/account.php?return=room&id=' . rawurlencode((string)$room['public_id']) . '&tab=security')) ?>" target="_blank" rel="noopener noreferrer">Open Security &amp; Privacy</a>
      <button class="btn" id="message-protection-auth-close" type="button">Close</button>
    </div>
  </div>
</div>
<div class="modal" id="voice-note-modal">
  <div class="modal-box voice-note-box">
    <div class="modal-head">
      <strong>Voice Note</strong>
      <button class="btn" id="voice-note-cancel" type="button">Cancel</button>
    </div>
    <div class="voice-note-status" id="voice-note-status">Recording...</div>
    <button class="btn btn-aqua" id="voice-note-stop" type="button">Stop and Send</button>
  </div>
</div>
<div class="modal" id="room-edit-modal">
  <form class="modal-box" id="room-edit-form" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="modal-head">
      <strong>Edit Room</strong>
      <button class="window-close" id="room-edit-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="room-edit-preview" id="room-edit-preview">
      <?php if ($room['background_path'] && str_starts_with((string)$room['background_mime'], 'video/') && !empty($room['background_thumb_path'])): ?>
      <img src="<?= e(media_url($room['background_thumb_path'])) ?>" alt="Current room background thumbnail">
      <?php elseif ($room['background_path'] && str_starts_with((string)$room['background_mime'], 'video/')): ?>
      <video muted loop playsinline preload="metadata"><source src="<?= e(media_url($room['background_path'])) ?>" type="<?= e($room['background_mime']) ?>"></video>
      <?php elseif ($room['background_path']): ?>
      <img src="<?= e(media_url($room['background_path'])) ?>" alt="Current room background">
      <?php else: ?>
      <div class="room-edit-preview-empty">No background selected</div>
      <?php endif; ?>
    </div>
    <label>Room name<input id="room-edit-name" name="name" value="<?= e($room['name']) ?>" required></label>
    <label>Background image or video
      <span class="file-picker">
        <input id="room-edit-background" type="file" name="background" accept="image/*,video/mp4,video/webm">
        <span class="file-picker-btn">Choose Background</span>
        <span class="file-picker-name" id="room-edit-background-name">No file selected</span>
      </span>
      <span class="upload-progress" id="room-edit-upload-progress" aria-live="polite">
        <span class="upload-progress-track"><span class="upload-progress-bar"></span></span>
        <span class="upload-progress-meta"><span class="upload-progress-msg">Waiting...</span><span class="upload-progress-pct">0%</span></span>
      </span>
    </label>
    <div class="ejection-list-wrap">
      <div class="side-title">Kicked Users</div>
      <div class="ejection-list" id="room-ejection-list">Loading...</div>
    </div>
    <div class="room-edit-actions">
      <button class="btn btn-primary" type="submit">Save Room</button>
      <?php if (room_access_can_delete($user, $room)): ?><button class="btn btn-danger" id="room-delete-open" type="button">Delete Room</button><?php endif; ?>
    </div>
  </form>
</div>
<div class="modal" id="room-delete-modal">
  <div class="modal-box warning-box">
    <div class="modal-head">
      <strong>Delete Room</strong>
      <button class="window-close" id="room-delete-close" type="button" aria-label="Close">×</button>
    </div>
    <p>This will delete the room and eject everyone currently inside it.</p>
    <div class="password-actions">
      <button class="btn btn-danger" id="room-delete-confirm" type="button">Delete Room</button>
      <button class="btn" id="room-delete-cancel" type="button">Cancel</button>
    </div>
  </div>
</div>
<div class="modal" id="room-effects-modal">
  <form class="modal-box room-effects-box" id="room-effects-form">
    <div class="modal-head">
      <strong>Room Effects</strong>
      <button class="window-close" id="room-effects-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="room-effect-current" id="room-effect-current"></div>
    <label>Effect
      <select id="room-effect-select" name="effect_key"></select>
    </label>
    <label>Duration
      <select id="room-effect-duration" name="duration_minutes">
        <option value="">Until disabled</option>
        <option value="1">1 minute</option>
        <option value="5">5 minutes</option>
        <option value="10">10 minutes</option>
        <option value="30">30 minutes</option>
        <option value="60">1 hour</option>
      </select>
    </label>
    <div class="room-effects-actions">
      <button class="btn btn-primary" type="submit">Start Effect</button>
      <button class="btn btn-danger" id="room-effect-stop" type="button">Stop Current</button>
    </div>
  </form>
</div>
<div class="modal" id="aura-modal">
  <div class="modal-box aura-box">
    <div class="modal-head">
      <strong>Auras</strong>
      <button class="window-close" id="aura-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="aura-preview-stage">
      <div class="aura-preview-wrap">
        <div class="avatar-aura-layer aura-preview-layer"><div class="avatar-aura-effect"></div></div>
        <img id="aura-preview-avatar" alt="Aura preview">
      </div>
    </div>
    <div class="aura-options" id="aura-options"></div>
    <div class="aura-actions">
      <button class="btn btn-primary" id="aura-set" type="button">Set</button>
      <button class="btn" id="aura-cancel" type="button">Cancel</button>
    </div>
  </div>
</div>
<div class="modal" id="host-warn-modal">
  <form class="modal-box host-action-box" id="host-warn-form">
    <div class="modal-head">
      <strong>Warn User</strong>
      <button class="window-close" id="host-warn-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="host-target-line" id="host-warn-target"></div>
    <label class="host-field">Warning message<textarea id="host-warn-message" maxlength="1000" rows="5" placeholder="Type the warning this user will see..." required></textarea></label>
    <div class="host-action-footer">
      <span class="minor">They must acknowledge it before continuing.</span>
      <button class="btn btn-danger" type="submit">Send Warning</button>
    </div>
  </form>
</div>
<div class="modal" id="host-kick-modal">
  <form class="modal-box host-action-box" id="host-kick-form">
    <div class="modal-head">
      <strong>Kick from Room</strong>
      <button class="window-close" id="host-kick-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="host-target-line" id="host-kick-target"></div>
    <label class="host-field host-duration-field">Duration
      <select id="host-kick-duration">
        <option value="5">5 minutes</option>
        <option value="15">15 minutes</option>
        <option value="30">30 minutes</option>
        <option value="60">60 minutes</option>
        <option value="1440">1440 minutes</option>
        <option value="permanent">Permanent</option>
      </select>
    </label>
    <button class="btn btn-danger host-kick-submit" type="submit">Kick from Room</button>
  </form>
</div>
<div class="modal" id="community-eject-modal">
  <form class="modal-box host-action-box" id="community-eject-form">
    <div class="modal-head">
      <strong>Community Eject</strong>
      <button class="window-close" id="community-eject-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="host-target-line" id="community-eject-target"></div>
    <label class="host-field host-duration-field">Duration
      <select id="community-eject-duration">
        <option value="5">5 minutes</option>
        <option value="15">15 minutes</option>
        <option value="30">30 minutes</option>
        <option value="60">60 minutes</option>
        <option value="1440">1440 minutes</option>
        <option value="permanent">Forever</option>
      </select>
    </label>
    <label class="host-field">Reason
      <textarea id="community-eject-reason" maxlength="1000"></textarea>
    </label>
    <button class="btn btn-danger host-kick-submit" type="submit">Community Eject</button>
  </form>
</div>
<div class="modal" id="host-notice-modal">
  <div class="modal-box warning-box">
    <div class="modal-head">
      <strong id="host-notice-title">Notice</strong>
    </div>
    <div id="host-notice-message"></div>
    <button class="btn btn-aqua" id="host-notice-understand" type="button" style="width:100%;margin-top:14px;">I understand</button>
  </div>
</div>
<div class="modal" id="link-icon-modal">
  <div class="modal-box link-icon-box">
    <div class="modal-head">
      <strong>Link Icon</strong>
      <button class="window-close" id="link-icon-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="link-icon-grid" id="link-icon-grid">
      <button class="link-icon-none" type="button" data-link-icon="none"><span aria-hidden="true"></span><strong>None</strong></button>
      <?php foreach ($linkIconCatalog as $icon): ?>
      <button type="button" data-link-icon="<?= e($icon['icon_name']) ?>"><img src="<?= e(app_url($icon['file_path'])) ?>" alt=""><span><?= e($icon['label']) ?></span></button>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<div class="modal" id="relationship-management-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="relationship-management-title">
  <div class="modal-box relationship-management-box" tabindex="-1">
    <div class="modal-head">
      <div>
        <strong id="relationship-management-title">Relationship</strong>
        <div class="minor" id="relationship-management-summary"></div>
      </div>
      <button class="window-close" id="relationship-management-close" type="button" aria-label="Close relationship management">&times;</button>
    </div>
    <div class="relationship-management-status" id="relationship-management-status" role="status" aria-live="polite"></div>
    <p class="minor" id="relationship-management-permission-help" hidden></p>
    <section class="relationship-management-section" id="relationship-management-request-section" hidden>
      <h3>Pending</h3>
      <ul class="relationship-request-list" id="relationship-management-requests"></ul>
    </section>
    <section class="relationship-management-section" id="relationship-management-join-section" hidden>
      <h3>Request to join</h3>
      <div class="relationship-seat-form">
        <label>Membership
          <select id="relationship-management-join-role">
            <option value="normal">Normal member</option>
            <option value="lap">Lap occupant</option>
          </select>
        </label>
        <label>Host
          <select id="relationship-management-join-host"></select>
        </label>
        <label>Side
          <select id="relationship-management-join-side">
            <option value="bottom-left">Left side</option>
            <option value="bottom-right">Right side</option>
          </select>
        </label>
        <button class="btn" id="relationship-management-request-join" type="button">Request</button>
      </div>
    </section>
    <section class="relationship-management-section" id="relationship-management-invite-section" hidden>
      <h3>Invite member</h3>
      <div class="relationship-seat-form">
        <label>Participant
          <select id="relationship-management-invite-target"></select>
        </label>
        <label>Membership
          <select id="relationship-management-invite-role">
            <option value="normal">Normal member</option>
            <option value="lap">Lap occupant</option>
          </select>
        </label>
        <label>Host
          <select id="relationship-management-invite-host"></select>
        </label>
        <button class="btn" id="relationship-management-invite" type="button">Invite</button>
      </div>
    </section>
    <section class="relationship-management-section" id="relationship-management-seat-section">
      <h3>Lap seats</h3>
      <ul class="relationship-seat-list" id="relationship-management-seats"></ul>
    </section>
    <section class="relationship-management-section">
      <h3>Members</h3>
      <ul class="relationship-member-list" id="relationship-management-members"></ul>
    </section>
    <section class="relationship-management-section relationship-management-settings" id="relationship-management-settings">
      <h3>Group settings</h3>
      <label>Joining
        <select id="relationship-management-join-policy" disabled>
          <option value="approval-required">Approval required</option>
          <option value="open">Open</option>
        </select>
      </label>
      <div class="relationship-order-list" id="relationship-management-order"></div>
      <label>Formation
        <select id="relationship-management-formation" disabled>
          <option value="horizontal-row">Horizontal Row</option>
          <option value="bottom-center-trio">Bottom-Center Trio</option>
          <option value="top-center-trio">Top-Center Trio</option>
          <option value="grid">Grid</option>
        </select>
      </label>
      <div class="minor" id="relationship-management-formation-status"></div>
      <label>Transition
        <select id="relationship-management-transition" disabled>
          <option value="snap">Snap</option>
          <option value="glide">Glide</option>
          <option value="fade-reposition">Fade and Reposition</option>
        </select>
      </label>
      <label>Dance
        <select id="relationship-management-dance" disabled>
          <option value="synchronized-sway">Synchronized Sway</option>
          <option value="synchronized-bounce">Synchronized Bounce</option>
        </select>
      </label>
      <div class="relationship-dance-controls">
        <button class="btn" id="relationship-management-dance-start" type="button" disabled>Start</button>
        <button class="btn" id="relationship-management-dance-stop" type="button" disabled>Stop</button>
        <span class="minor" id="relationship-management-dance-status" role="status" aria-live="polite">Stopped</span>
      </div>
      <label class="relationship-spacing-control">Row spacing
        <input id="relationship-management-spacing" type="range" min="0" max="64" step="1" value="0" disabled>
        <output id="relationship-management-spacing-value">0 px</output>
      </label>
      <button class="btn" id="relationship-management-spacing-reset" type="button" disabled>Reset spacing</button>
    </section>
    <div class="relationship-management-footer" id="relationship-management-footer">
      <button class="btn btn-danger" id="relationship-management-leave" type="button" disabled>Leave Relationship</button>
      <button class="btn btn-danger" id="relationship-management-dissolve" type="button" disabled>Dissolve Relationship</button>
    </div>
    <div class="relationship-confirm" id="relationship-management-confirm" hidden>
      <strong id="relationship-management-confirm-title">Confirm action</strong>
      <p id="relationship-management-confirm-message"></p>
      <div>
        <button class="btn" id="relationship-management-confirm-cancel" type="button">Cancel</button>
        <button class="btn btn-danger" id="relationship-management-confirm-accept" type="button">Confirm</button>
      </div>
    </div>
  </div>
</div>
<div class="modal" id="link-choice-modal">
  <div class="modal-box link-choice-box">
    <h2>Interact</h2>
    <p id="link-choice-prompt">What would you like to do?</p>
    <div class="link-choice-actions" id="link-choice-actions">
      <button class="btn link-choice-link" id="link-choice-link" type="button">🔗 Link Avatars</button>
      <button class="btn link-choice-lap" id="link-choice-lap" type="button">🧸 Sit in Lap</button>
      <button class="btn link-choice-cancel" id="link-choice-cancel" type="button">Cancel</button>
    </div>
    <div class="link-choice-seat" id="link-choice-seat" hidden>
      <button class="btn" id="link-choice-bottom-left" type="button">Left side</button>
      <button class="btn" id="link-choice-bottom-right" type="button">Right side</button>
      <button class="btn link-choice-cancel" id="link-choice-seat-cancel" type="button">Cancel</button>
    </div>
  </div>
</div>
<div class="modal" id="warning-modal">
  <div class="modal-box warning-box">
    <div class="modal-head">
      <strong>Warning</strong>
      <button class="window-close" id="warning-close" type="button" aria-label="Close">×</button>
    </div>
    <div id="warning-message">You cannot link with this user.</div>
  </div>
</div>
<div class="modal" id="delete-message-modal">
  <div class="modal-box warning-box">
    <div class="modal-head">
      <strong>Delete Message</strong>
      <button class="window-close" id="delete-message-close" type="button" aria-label="Close">×</button>
    </div>
    <div>Are you sure you want to delete this message?</div>
    <div class="delete-message-actions">
      <button class="btn" id="delete-message-cancel" type="button">Cancel</button>
      <button class="btn btn-danger" id="delete-message-confirm" type="button">Delete Message</button>
    </div>
  </div>
</div>
<div class="modal" id="gesture-delete-modal">
  <div class="modal-box warning-box">
    <div class="modal-head">
      <strong>Delete Gesture</strong>
      <button class="window-close" id="gesture-delete-close" type="button" aria-label="Close">×</button>
    </div>
    <div id="gesture-delete-message">Are you sure you want to delete this gesture?</div>
    <div class="delete-message-actions">
      <button class="btn" id="gesture-delete-cancel" type="button">Cancel</button>
      <button class="btn btn-danger" id="gesture-delete-confirm" type="button">Delete Gesture</button>
    </div>
  </div>
</div>
<div class="modal" id="clear-room-history-modal">
  <div class="modal-box warning-box">
    <div class="modal-head">
      <strong>Clear Room History</strong>
      <button class="window-close" id="clear-room-history-close" type="button" aria-label="Close">×</button>
    </div>
    <div>This will remove the room chat history for everyone in the room.</div>
    <div class="delete-message-actions">
      <button class="btn" id="clear-room-history-cancel" type="button">Cancel</button>
      <button class="btn btn-danger" id="clear-room-history-confirm" type="button">Clear History</button>
    </div>
  </div>
</div>
<div class="modal" id="avatar-size-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="avatar-size-title">
  <form class="modal-box avatar-size-box" id="avatar-size-form">
    <div class="modal-head">
      <div>
        <h2 id="avatar-size-title">Avatar Display Size</h2>
        <div class="minor" id="avatar-size-cap"></div>
      </div>
      <button class="icon-btn" id="avatar-size-close" type="button" aria-label="Close">&times;</button>
    </div>
    <section id="avatar-size-avatar-fields">
      <p class="minor">Choose proportional sizing or exact width and height. Exact sizing can stretch the display but never changes your uploaded image. Lap seating still fits inside the host avatar.</p>
      <label>Display mode
        <select id="avatar-size-mode"><option value="natural">Preserve proportions</option><option value="exact">Exact width and height</option></select>
      </label>
      <label>Maximum edge
        <span class="avatar-size-input-with-unit"><input id="avatar-size-edge" name="avatar_display_size_px" type="number" min="42" step="1" inputmode="numeric"><span>px</span></span>
      </label>
      <div class="avatar-size-readout" id="avatar-size-current"></div>
      <div class="avatar-size-dimensions" id="avatar-exact-size-fields" hidden>
        <label>Width (px)<input id="avatar-exact-width" type="number" min="42" step="1" inputmode="numeric" disabled></label>
        <label>Height (px)<input id="avatar-exact-height" type="number" min="42" step="1" inputmode="numeric" disabled></label>
      </div>
      <div id="avatar-exact-match-wrap" hidden>
        <label>Match a linked member once<select id="avatar-exact-match-participant"></select></label>
        <button class="btn" id="avatar-exact-match" type="button">Match width and height</button>
        <p class="minor">Copies their currently displayed avatar size. Press Save to apply; later changes will not automatically change yours.</p>
      </div>
    </section>
    <section id="avatar-size-webcam-fields" hidden>
      <label>Size preset
        <select id="avatar-size-webcam-preset">
          <option value="match">Match current avatar size</option>
          <option value="small">Small &mdash; 120 &times; 120</option>
          <option value="medium">Medium &mdash; 160 &times; 160</option>
          <option value="large">Large &mdash; 200 &times; 200</option>
          <option value="custom">Custom</option>
        </select>
      </label>
      <div class="avatar-size-dimensions">
        <label>Width (px)
          <input id="avatar-size-webcam-width" name="webcam_display_width_px" type="number" min="42" step="1" inputmode="numeric">
        </label>
        <label>Height (px)
          <input id="avatar-size-webcam-height" name="webcam_display_height_px" type="number" min="42" step="1" inputmode="numeric">
        </label>
      </div>
      <label class="btn avatar-size-lock"><input id="avatar-size-aspect-lock" type="checkbox" checked> <span>Lock aspect ratio</span></label>
      <div id="avatar-size-match-wrap" hidden>
        <label>Match a linked member once
          <select id="avatar-size-match-participant"></select>
        </label>
        <button class="btn" id="avatar-size-match" type="button">Use selected size</button>
      </div>
    </section>
    <div class="admin-row-status" id="avatar-size-status" aria-live="polite"></div>
    <div class="delete-message-actions">
      <button class="btn" id="avatar-size-reset" type="button">Use server default</button>
      <button class="btn" id="avatar-size-cancel" type="button">Cancel</button>
      <button class="btn btn-primary" id="avatar-size-save" type="submit">Save</button>
    </div>
  </form>
</div>
<div id="ctx-menu" role="menu" aria-label="Avatar actions">
  <div id="ctx-identity-header" class="ctx-identity-header" role="note" aria-live="polite">
    <strong id="ctx-identity-display-name" hidden></strong>
    <span id="ctx-identity-roles"></span>
  </div>
  <div class="ctx-divider ctx-identity-divider" aria-hidden="true"></div>
  <button id="ctx-profile" type="button">User Profile</button>
  <div class="ctx-submenu-wrap" id="ctx-avatar-settings-wrap">
    <button id="ctx-avatar-settings" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="ctx-avatar-settings-submenu">Avatar Settings <span aria-hidden="true">›</span></button>
    <div class="ctx-submenu" id="ctx-avatar-settings-submenu" role="menu" aria-label="Avatar settings">
      <button id="ctx-change-avatar" type="button">Change Avatar</button>
      <button id="ctx-avatar-size" type="button">Avatar Display Size</button>
      <div class="ctx-submenu-wrap" id="ctx-orientation-wrap">
        <button id="ctx-orientation" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="ctx-orientation-submenu">Orientation <span aria-hidden="true">›</span></button>
        <div class="ctx-submenu" id="ctx-orientation-submenu" role="menu" aria-label="Avatar orientation">
          <button type="button" role="menuitemradio" data-avatar-orientation="original" data-label="Original">Original</button>
          <button type="button" role="menuitemradio" data-avatar-orientation="flip-horizontal" data-label="Flip Horizontally">Flip Horizontally</button>
          <button type="button" role="menuitemradio" data-avatar-orientation="flip-vertical" data-label="Flip Vertically">Flip Vertically</button>
          <button type="button" role="menuitemradio" data-avatar-orientation="flip-both" data-label="Flip Horizontally and Vertically">Flip Horizontally and Vertically</button>
        </div>
      </div>
      <button id="ctx-auras" type="button">Auras</button>
      <div class="ctx-divider" aria-hidden="true"></div>
      <button id="ctx-change-nameplate" type="button">Change Nameplate Image</button>
      <button id="ctx-remove-nameplate" type="button">Remove Nameplate Image</button>
      <button id="ctx-webcam-size" type="button">Webcam Size</button>
    </div>
  </div>
  <button id="ctx-toggle-webcam" type="button">Enable Webcam</button>
  <button id="ctx-dm" type="button">Send DM</button>
  <button id="ctx-poke" type="button" hidden>Poke</button>
  <button id="ctx-interact" type="button">Link / Sit in Lap</button>
  <div class="ctx-submenu-wrap" id="ctx-hide-wrap">
    <button id="ctx-hide" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="ctx-hide-submenu">Hide / Show <span aria-hidden="true">›</span></button>
    <div class="ctx-submenu" id="ctx-hide-submenu" role="menu" aria-label="Hide or show this person's media">
      <button id="ctx-avatar-visibility" type="button">Hide this avatar until it changes</button>
      <button id="ctx-avatar-user-visibility" type="button">Hide avatars from this user</button>
      <button id="ctx-nameplate-visibility" type="button">Hide this nameplate until it changes</button>
      <button id="ctx-nameplate-user-visibility" type="button">Hide nameplates from this user</button>
      <button id="ctx-gesture-sender-visibility" type="button">Hide gesture media from this user</button>
      <button id="ctx-webcam-visibility" type="button">Hide this webcam for me</button>
      <button id="ctx-webcam-receive" type="button">Stop receiving this webcam</button>
    </div>
  </div>
  <button id="ctx-lap-dance" type="button" aria-pressed="false">Start Lap Dance</button>
  <button id="ctx-lap-bounce" type="button" aria-pressed="false">Start Lap Bounce</button>
  <div class="ctx-submenu-wrap" id="ctx-block-mute-wrap">
    <button id="ctx-block-mute" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="ctx-block-mute-submenu">Block / Mute <span aria-hidden="true">›</span></button>
    <div class="ctx-submenu" id="ctx-block-mute-submenu" role="menu" aria-label="Block or mute this person">
      <button id="ctx-block" class="danger" type="button">Block</button>
      <button id="ctx-unblock" type="button">Unblock</button>
      <button id="ctx-mute" type="button">Mute</button>
    </div>
  </div>
  <button id="ctx-manage-relationship" type="button">Manage Relationship</button>
  <button id="ctx-unlink" class="danger" type="button">Unlink</button>
  <div class="ctx-divider" id="ctx-tools-divider"></div>
  <div class="ctx-submenu-wrap" id="ctx-tools-wrap">
    <button id="ctx-tools" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="ctx-tools-submenu"><span id="ctx-tools-label">Admin Tools</span> <span aria-hidden="true">›</span></button>
    <div class="ctx-submenu" id="ctx-tools-submenu" role="menu" aria-label="Moderation tools">
      <button id="ctx-host-warn" type="button">Warn</button>
      <button id="ctx-host-kick" class="danger" type="button">Kick from Room</button>
      <button id="ctx-community-eject" class="danger" type="button">Community Eject</button>
    </div>
  </div>
  <div class="ctx-divider" id="ctx-transfer-divider"></div>
  <button id="ctx-send-file-gesture" type="button">Send File or Gesture</button>
</div>
<div id="text-ctx-menu">
  <button id="text-copy" type="button">Copy</button>
  <button id="text-cut" type="button">Cut</button>
  <button id="text-paste" type="button">Paste</button>
</div>
<div id="msg-action-menu">
  <div class="msg-react-row">
    <button type="button" data-msg-reaction="❤️">❤️</button>
    <button type="button" data-msg-reaction="👍">👍</button>
    <button type="button" data-msg-reaction="👎">👎</button>
    <button type="button" data-msg-reaction="😂">😂</button>
    <button type="button" data-msg-reaction="😌">😌</button>
    <button type="button" data-msg-reaction="✅">✅</button>
  </div>
  <button id="msg-reply-action" type="button">Reply</button>
  <button id="msg-muted-reveal-action" type="button" hidden>Reveal message</button>
  <button id="msg-gesture-visibility-action" type="button" hidden>Hide this gesture for me</button>
  <button id="msg-edit-action" type="button">Edit</button>
  <button id="msg-delete-action" class="danger" type="button">Delete</button>
</div>
<div id="tab-ctx-menu">
  <button id="tab-clear-history" type="button">Clear History</button>
  <button id="tab-close-dm" type="button">Close DM</button>
  <button id="tab-manage-relationship" type="button">Manage Relationship</button>
  <button id="tab-unlink" class="danger" type="button">Unlink</button>
</div>
<div id="room-action-menu">
  <button id="live-website-room-make-official" type="button" hidden>Make Live Website Room Official</button>
  <button id="room-action-edit" type="button">Edit Room</button>
  <button id="room-action-refresh-import-text" type="button" hidden>Refresh imported appearance</button>
  <button id="room-action-effects" type="button">Room Effects</button>
  <button id="room-action-clear-history" class="danger" type="button">Clear Room History</button>
</div>
<div id="room-menu">
  <?php if (in_array((string)$user['role'], ['admin', 'developer'], true)): ?>
  <a id="admin-link" data-room-navigation="utility" href="<?= e(app_url('/admin.php?return=room&id=' . rawurlencode((string)$room['public_id']))) ?>" target="_blank" rel="noopener noreferrer">Admin</a>
  <?php endif; ?>
  <button id="chat-options-btn" type="button">Chat Options</button>
  <button id="report-problem-btn" type="button">Report Problem</button>
  <a id="account-link" data-room-navigation="utility" href="<?= e(app_url('/account.php?return=room&id=' . rawurlencode((string)$room['public_id']))) ?>" target="_blank" rel="noopener noreferrer">Account</a>
<button type="button" data-confirm-identity>Confirm Identity</button>
<button id="lock-session-btn" type="button"><img src="<?= e(app_url('/assets/images/secure.png')) ?>" alt="">Lock Session</button>
  <button id="rooms-link" type="button" data-href="<?= e(app_url('/lobby.php')) ?>"><img src="<?= e(app_url('/assets/images/lobby.png')) ?>" alt="">Lobby</button>
  <button id="logout-link" type="button"><img src="<?= e(app_url('/assets/images/logout.png')) ?>" alt="">Log Out</button>
</div>
<form id="logout-form" method="post" action="<?= e(app_url('/logout.php')) ?>" hidden>
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
</form>
<div class="modal" id="chat-options-modal" aria-hidden="true">
  <div class="modal-box chat-options-box chat-options-compact" role="dialog" aria-modal="true" aria-labelledby="chat-options-title">
    <div class="modal-head">
      <strong id="chat-options-title">Chat Options</strong>
      <button class="window-close" id="chat-options-close" type="button" aria-label="Close">&times;</button>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="chat-display-label">Message display</span>
        <div class="chat-option-controls"><fieldset class="segmented-radio" aria-labelledby="chat-display-label">
  <label><input type="radio" name="chat-display-mode" value="detailed"><span>Detailed</span></label>
  <label><input type="radio" name="chat-display-mode" value="compact"><span>Compact</span></label>
</fieldset></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Message display" title="About Message display">i</summary><div class="chat-option-help-copy"><p class="settings-choice-description" id="chat-display-description"></p></div></details>
    </div><section class="gesture-chat-options" aria-labelledby="message-chime-title">    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="message-chime-title">DM and link message chime</span>
        <div class="chat-option-controls"><button type="button" class="btn btn-primary" id="message-chime-toggle" aria-labelledby="message-chime-title message-chime-toggle" aria-pressed="true">On</button>
<button type="button" class="btn" id="message-chime-preview">Play chime</button></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About DM and link message chime" title="About DM and link message chime">i</summary><div class="chat-option-help-copy">Sounds for new DMs and linked-group messages. Normally silent while you are viewing that conversation; the focused-chat option also allows alerts while focused. Use the interval to limit alerts, or choose Every message. Viewing the conversation resets the cooldown. No repeating reminders. Preferences are saved for this account in this browser.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name">Volume</span>
        <div class="chat-option-controls"><div class="gesture-preference-actions chat-option-stepper" role="group" aria-label="Message chime volume">
  <button type="button" class="btn" id="message-chime-volume-down" aria-label="Decrease message chime volume by 25 percent">-</button>
  <output id="message-chime-volume-value" aria-live="polite" aria-atomic="true">100%</output>
  <button type="button" class="btn" id="message-chime-volume-up" aria-label="Increase message chime volume by 25 percent">+</button>
</div></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Volume" title="About Volume">i</summary><div class="chat-option-help-copy">Chime volume ranges from 25% to 200%, adjusted in 25% steps. This volume applies to DM, link, and Chat Room message chimes.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="message-chime-focused-label">Also chime while viewing this chat</span>
        <div class="chat-option-controls"><button type="button" class="btn" id="message-chime-focused-toggle" aria-labelledby="message-chime-focused-label message-chime-focused-toggle" aria-pressed="false">Off</button></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Also chime while viewing this chat" title="About Also chime while viewing this chat">i</summary><div class="chat-option-help-copy">Off by default. When on, new incoming messages can also chime in the conversation you are viewing, using the same volume and interval. The chime switch for that message type must also be on.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="message-chime-interval-title">DM and link chime interval</span>
        <div class="chat-option-controls"><div class="gesture-preference-actions chat-option-stepper" role="group" aria-labelledby="message-chime-interval-title">
  <button type="button" class="btn" id="message-chime-interval-down" aria-label="Shorten DM and link chime interval by 5 seconds">-</button>
  <output id="message-chime-interval-value" aria-live="polite" aria-atomic="true">30 seconds</output>
  <button type="button" class="btn" id="message-chime-interval-up" aria-label="Lengthen DM and link chime interval by 5 seconds">+</button>
</div>
<div class="gesture-preference-actions chat-option-presets" id="message-chime-interval-presets" role="group" aria-label="DM and link chime interval presets">
  <button type="button" class="btn" data-chime-interval="0" aria-pressed="false">Every message</button>
  <button type="button" class="btn btn-primary" data-chime-interval="30" aria-pressed="true">30 seconds</button>
  <button type="button" class="btn" data-chime-interval="60" aria-pressed="false">1 minute</button>
  <button type="button" class="btn" data-chime-interval="300" aria-pressed="false">5 minutes</button>
</div></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About DM and link chime interval" title="About DM and link chime interval">i</summary><div class="chat-option-help-copy">Minimum time between alerts, in 5-second steps. Default: 30 seconds. Every message removes the cooldown; 5 minutes is the longest interval.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="poke-option-title">Allow other users to poke me</span>
        <div class="chat-option-controls"><button type="button" class="btn" id="poke-toggle" aria-labelledby="poke-option-title poke-toggle" aria-pressed="false" disabled>Loading</button></div>
      </div>
      <p class="settings-choice-description">Pokes show a private notice. Blocked users and users muted for notices cannot poke you. This choice follows your account.</p>
    </div><div class="chat-option-item"><div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="room-message-chime-title">Chat Room message chime</span>
        <div class="chat-option-controls"><button type="button" class="btn" id="room-message-chime-toggle" aria-labelledby="room-message-chime-title room-message-chime-toggle" aria-pressed="false">Off</button></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Chat Room message chime" title="About Chat Room message chime">i</summary><div class="chat-option-help-copy">Off by default. Chimes for other people's new messages in this room, using the same sound, volume and focused-chat preference. Separate from DM/link alerts; does not include Community Chat or game chat.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="room-message-chime-interval-title">Chat Room chime interval</span>
        <div class="chat-option-controls"><div class="gesture-preference-actions chat-option-stepper" role="group" aria-labelledby="room-message-chime-interval-title">
  <button type="button" class="btn" id="room-message-chime-interval-down" aria-label="Shorten Chat Room chime interval by 5 seconds">-</button>
  <output id="room-message-chime-interval-value" aria-live="polite" aria-atomic="true">30 seconds</output>
  <button type="button" class="btn" id="room-message-chime-interval-up" aria-label="Lengthen Chat Room chime interval by 5 seconds">+</button>
</div>
<div class="gesture-preference-actions chat-option-presets" id="room-message-chime-interval-presets" role="group" aria-label="Chat Room chime interval presets">
  <button type="button" class="btn" data-room-chime-interval="0" aria-pressed="false">Every message</button>
  <button type="button" class="btn btn-primary" data-room-chime-interval="30" aria-pressed="true">30 seconds</button>
  <button type="button" class="btn" data-room-chime-interval="60" aria-pressed="false">1 minute</button>
  <button type="button" class="btn" data-room-chime-interval="300" aria-pressed="false">5 minutes</button>
</div></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Chat Room chime interval" title="About Chat Room chime interval">i</summary><div class="chat-option-help-copy">Minimum time between alerts, in 5-second steps. Default: 30 seconds. Every message removes the cooldown; 5 minutes is the longest interval. This timer is independent of DM/link alerts. Returning to read the room resets only the room timer.</div></details>
    </div><p class="minor chat-option-feedback" id="message-chime-status" role="status" aria-live="polite"></p></section><section class="gesture-chat-options" aria-labelledby="avatar-motion-title">    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="avatar-motion-title">Avatar motion</span>
        <div class="chat-option-controls"><div class="gesture-preference-actions" id="avatar-motion-options" role="group" aria-labelledby="avatar-motion-title">
  <button type="button" class="btn" data-avatar-motion="system" aria-pressed="false">Follow device setting</button>
  <button type="button" class="btn btn-primary" data-avatar-motion="on" aria-pressed="true">Animations on</button>
  <button type="button" class="btn" data-avatar-motion="reduced" aria-pressed="false">Reduced motion</button>
</div></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Avatar motion" title="About Avatar motion">i</summary><div class="chat-option-help-copy"><p>Controls avatar dances, including lap dance and lap bounce. Animations on overrides your device's reduced-motion preference for these effects only; it does not change Windows, games, or other members' preferences.</p><p class="minor" id="avatar-motion-status" role="status" aria-live="polite"></p></div></details>
    </div></section><section class="gesture-chat-options" aria-labelledby="identity-chat-options-title"><div class="settings-choice-name chat-option-section-title" id="identity-chat-options-title">Identity appearance</div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name"><label for="show-role-message-colors">Show staff role colors across ordinary messages</label></span>
        <div class="chat-option-controls"><input id="show-role-message-colors" type="checkbox" checked></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Show staff role colors across ordinary messages" title="About Show staff role colors across ordinary messages">i</summary><div class="chat-option-help-copy">Uses the configured Owner, Administrator, Moderator, Guide, and Developer color across the full message row. Authorized Important messages always retain their sender's role treatment.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name"><label for="show-role-message-titles">Show role titles in messages</label></span>
        <div class="chat-option-controls"><input id="show-role-message-titles" type="checkbox"></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Show role titles in messages" title="About Show role titles in messages">i</summary><div class="chat-option-help-copy">Shows role badges beside names in chat messages. This is off by default and does not hide role badges in the Chatting or voice lists.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name"><label for="show-identity-nameplates">Show nameplate images</label></span>
        <div class="chat-option-controls"><input id="show-identity-nameplates" type="checkbox" checked></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Show nameplate images" title="About Show nameplate images">i</summary><div class="chat-option-help-copy">Shows each member's separate nameplate image across that person's Chatting or voice identity card. Nameplate visibility, blocked members, and this viewer-only switch control it independently from avatars.</div></details>
    </div></section><section class="gesture-chat-options" aria-labelledby="gesture-chat-options-title"><div class="settings-choice-name chat-option-section-title" id="gesture-chat-options-title">Gestures</div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name"><label for="gesture-show-animations">Show gesture animations</label></span>
        <div class="chat-option-controls"><input id="gesture-show-animations" type="checkbox"></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Show gesture animations" title="About Show gesture animations">i</summary><div class="chat-option-help-copy">Show the current gesture animation in messages and avatar speech.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name"><label for="gesture-show-text">Show gesture text</label></span>
        <div class="chat-option-controls"><input id="gesture-show-text" type="checkbox"></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Show gesture text" title="About Show gesture text">i</summary><div class="chat-option-help-copy">Show canonical text in the format (Gesture) Gesture Text.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name"><label for="gesture-play-sounds">Play gesture sounds</label></span>
        <div class="chat-option-controls"><input id="gesture-play-sounds" type="checkbox"></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Play gesture sounds" title="About Play gesture sounds">i</summary><div class="chat-option-help-copy">Allow the current supported gesture-sound path to begin playback.</div></details>
    </div><div class="gesture-preference-actions chat-option-reset"><button class="btn" id="gesture-options-reset" type="button">Reset gesture options</button><span class="minor" id="gesture-options-status" role="status" aria-live="polite"></span></div></section>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="webcam-visibility-label">Webcam display</span>
        <div class="chat-option-controls"><fieldset class="segmented-radio segmented-radio-wide" aria-labelledby="webcam-visibility-label">
  <label><input type="radio" name="webcam-visibility-mode" value="show"><span>Show webcams</span></label>
  <label><input type="radio" name="webcam-visibility-mode" value="hide"><span>Show avatars</span></label>
</fieldset></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Webcam display" title="About Webcam display">i</summary><div class="chat-option-help-copy">Hiding webcams changes only what you see. Video may still be received.</div></details>
    </div>    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="webcam-receive-label">Webcam receiving</span>
        <div class="chat-option-controls"><fieldset class="segmented-radio segmented-radio-wide" aria-labelledby="webcam-receive-label">
  <label><input type="radio" name="webcam-receive-mode" value="receive"><span>Receive video</span></label>
  <label><input type="radio" name="webcam-receive-mode" value="stop"><span>Stop receiving</span></label>
</fieldset></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Webcam receiving" title="About Webcam receiving">i</summary><div class="chat-option-help-copy">Stops inbound webcam video while keeping voice available.</div></details>
    </div><p class="settings-choice-description webcam-capability-notice" id="webcam-capability-notice" hidden>Webcam use is disabled for this installation.</p><section class="hidden-avatar-options" aria-labelledby="hidden-avatar-options-title">    <div class="chat-option-item">
      <div class="settings-choice-row chat-option-line">
        <span class="settings-choice-name" id="hidden-avatar-options-title">Hidden Avatars</span>
        <div class="chat-option-controls"><button class="btn" id="hidden-avatar-show-all" type="button" hidden>Show all</button></div>
      </div>
      <details class="chat-option-help"><summary aria-label="About Hidden Avatars" title="About Hidden Avatars">i</summary><div class="chat-option-help-copy">These choices affect only what you see. Show all restores every avatar you have hidden.</div></details>
    </div>      <div id="hidden-avatar-list" class="hidden-avatar-list"></div>
      <div id="hidden-avatar-empty" class="minor">No avatars are hidden.</div>
      <div class="hidden-avatar-confirm" id="hidden-avatar-confirm" hidden>
        <span>Show every avatar you have hidden?</span>
        <div><button class="btn" id="hidden-avatar-show-all-cancel" type="button">Cancel</button><button class="btn btn-primary" id="hidden-avatar-show-all-confirm" type="button">Show all</button></div>
      </div>
      <div class="minor" id="hidden-avatar-status" role="status" aria-live="polite"></div>
    </section>
    <div class="chat-options-actions"><button class="btn" id="webcam-options-reset" type="button">Reset webcam options</button></div>
  </div>
</div>
<div class="modal" id="report-problem-modal" aria-hidden="true">
  <div class="modal-box diagnostic-report-box" role="dialog" aria-modal="true" aria-labelledby="report-problem-title">
    <div class="modal-head">
      <strong id="report-problem-title">Report Problem</strong>
      <button class="window-close" id="report-problem-close" type="button" aria-label="Close">×</button>
    </div>
    <form id="report-problem-form">
      <label>What went wrong?
        <textarea id="report-problem-summary" maxlength="500" rows="5" required></textarea>
      </label>
      <label class="diagnostic-screenshot-option" hidden><input id="report-problem-screenshot" type="checkbox"> Include a locally censored schematic</label>
      <p class="minor">Reports exclude chat contents, credentials, private files, raw media, SDP, and ICE.</p>
      <div class="minor" id="report-problem-status" role="status" aria-live="polite" hidden></div>
      <div class="modal-actions"><button class="btn btn-primary" type="submit">Submit Report</button></div>
    </form>
  </div>
</div>
<div class="session-lock" id="session-lock" aria-hidden="true">
  <form class="session-lock-box" id="session-lock-form">
    <div class="session-lock-brand">
      <img class="<?= $branding['has_custom_logo'] ? 'custom-brand-logo' : '' ?>" src="<?= e(app_url($branding['compact_logo_path'])) ?>" alt="<?= e($branding['effective_name']) ?>">
      <div>
        <strong>Session Locked</strong>
        <span><?= e($user['display_name']) ?></span>
      </div>
    </div>
    <label>Account password
      <input id="session-lock-password" type="password" autocomplete="current-password">
    </label>
    <div class="session-lock-error" id="session-lock-error" role="alert"></div>
    <button class="btn btn-primary" type="submit">Unlock Session</button>
  </form>
</div>
<div id="media-picker" hidden>
  <div class="media-picker-tabs" role="tablist" aria-label="Media picker">
    <button class="active" id="media-tab-gifs" role="tab" aria-selected="true" aria-controls="media-panel-gifs" type="button" data-media-tab="gifs">GIFs</button>
    <button id="media-tab-server-gestures" role="tab" aria-selected="false" aria-controls="media-panel-server-gestures" type="button" data-media-tab="server-gestures">Server Gestures</button>
    <button id="media-tab-personal-gestures" role="tab" aria-selected="false" aria-controls="media-panel-personal-gestures" type="button" data-media-tab="personal-gestures">Personal Gestures</button>
    <button id="media-tab-emojis" role="tab" aria-selected="false" aria-controls="media-panel-emojis" type="button" data-media-tab="emojis">Emojis</button>
    <button id="media-tab-custom-emojis" role="tab" aria-selected="false" aria-controls="media-panel-custom-emojis" type="button" data-media-tab="custom-emojis">Custom Emojis</button>
    <button id="media-tab-gestures" role="tab" aria-selected="false" aria-controls="media-panel-gestures" type="button" data-media-tab="gestures" hidden>Gestures</button>
  </div>
  <div class="media-search-row">
    <input id="media-search-input" type="search" placeholder="Search GIFs" autocomplete="off">
  </div>
  <div class="media-panel active" id="media-panel-gifs">
    <div class="gif-results" id="gif-results">
      <div class="minor">Search for a GIF.</div>
    </div>
  </div>
  <div class="media-panel gesture-catalog-panel" id="media-panel-server-gestures" role="tabpanel" aria-labelledby="media-tab-server-gestures">
    <div class="gesture-catalog-toolbar">
      <label>Search Server Gestures<input id="server-gesture-search" type="search" maxlength="120" autocomplete="off"></label>
      <label>Sort<select id="server-gesture-sort"><option value="last_uploaded">Last uploaded</option><option value="file_name">File name A–Z</option><option value="custom">Custom order</option></select></label>
    </div>
    <div class="gesture-reorder-guidance minor" id="server-gesture-reorder-guidance" hidden>Clear the search to rearrange gestures.</div>
    <div class="gesture-grid" id="server-gesture-grid"></div>
    <div class="gesture-pager" id="server-gesture-pager" aria-label="Server Gesture pages"></div>
    <div class="gesture-tray" id="server-gesture-status" role="status" aria-live="polite"></div>
    <details class="hidden-gesture-section" id="hidden-gesture-section">
      <summary>Hidden Gestures <span id="hidden-gesture-count">0</span></summary>
      <div class="gesture-catalog-toolbar"><label>Search Hidden Gestures<input id="hidden-gesture-search" type="search" maxlength="120" autocomplete="off"></label></div>
      <div class="hidden-gesture-actions"><button class="btn" id="hidden-gesture-show-selected" type="button">Show selected again</button><button class="btn btn-danger" id="hidden-gesture-show-all" type="button">Show all hidden gestures again</button></div>
      <div class="hidden-gesture-confirm" id="hidden-gesture-confirm" hidden><span>Show every hidden gesture again?</span><button class="btn btn-primary" id="hidden-gesture-confirm-yes" type="button">Show all</button><button class="btn" id="hidden-gesture-confirm-no" type="button">Cancel</button></div>
      <div class="hidden-gesture-list" id="hidden-gesture-list"></div>
      <div class="minor" id="hidden-gesture-status" role="status" aria-live="polite"></div>
    </details>
  </div>
  <div class="media-panel gesture-catalog-panel" id="media-panel-personal-gestures" role="tabpanel" aria-labelledby="media-tab-personal-gestures">
    <div class="gesture-catalog-toolbar">
      <button class="btn btn-primary" id="personal-gesture-create" type="button"<?= $gestureMakerAvailable ? '' : ' hidden disabled' ?>>Create / Edit Gestures</button>
      <label>Search Personal Gestures<input id="personal-gesture-search" type="search" maxlength="120" autocomplete="off"></label>
      <label>Sort<select id="personal-gesture-sort"><option value="last_uploaded">Last uploaded</option><option value="file_name">File name A–Z</option><option value="custom">Custom order</option></select></label>
    </div>
    <div class="gesture-reorder-guidance minor" id="personal-gesture-reorder-guidance" hidden>Clear the search to rearrange gestures.</div>
    <input class="hidden-file-input" id="gesture-file-input" type="file" accept=".agst,application/zip">
    <div class="gesture-grid" id="personal-gesture-grid"></div>
    <div class="gesture-pager" id="personal-gesture-pager" aria-label="Personal Gesture pages"></div>
    <div class="gesture-tray" id="personal-gesture-status" role="status" aria-live="polite"></div>
  </div>
  <div class="media-panel" id="media-panel-gestures" role="tabpanel" aria-labelledby="media-tab-gestures">
    <div class="gesture-grid" id="gesture-grid"></div>
    <div class="gesture-pager">
      <button class="btn" id="gesture-prev" type="button">Previous</button>
      <span id="gesture-page-label">Page 1</span>
      <button class="btn" id="gesture-next" type="button">Next</button>
    </div>
    <div class="gesture-tray" id="gesture-tray"></div>
  </div>
  <div class="media-panel" id="media-panel-emojis">
    <div class="emoji-grid" id="emoji-grid"></div>
  </div>
<div class="media-panel" id="media-panel-custom-emojis"><div id="custom-emoji-picker"><p class="minor">Loading custom emojis...</p></div></div>
</div>
<div id="gesture-action-menu" class="gesture-action-menu" role="menu" aria-label="Gesture actions" hidden></div>
<div class="modal" id="gesture-management-modal" role="dialog" aria-modal="true" aria-labelledby="gesture-management-title">
  <div class="modal-box gesture-management-box">
    <div class="modal-head">
      <strong id="gesture-management-title">My Gestures</strong>
      <button class="window-close" id="gesture-management-close" type="button" aria-label="Close gesture management">×</button>
    </div>
    <p class="minor">Create a private Personal Gesture or edit one you own. The active room remains open.</p>
    <button class="btn btn-primary" id="gesture-management-create" type="button">Create New Gesture</button>
    <div class="gesture-management-list" id="gesture-management-list" aria-label="My Gestures"></div>
    <div class="gesture-pager" id="gesture-management-pager" aria-label="My Gestures pages"></div>
    <div class="gesture-tray" id="gesture-management-status" role="status" aria-live="polite"></div>
  </div>
</div>
<input type="file" id="avatar-file-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
<input type="file" id="nameplate-file-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
<div class="modal" id="locate-modal">
  <div class="modal-box locate-box">
    <div class="modal-head">
      <strong>Locate Friends</strong>
      <button class="window-close" id="locate-close" type="button" aria-label="Close">×</button>
    </div>
    <label>Friend name
      <input id="friend-search" autocomplete="off" placeholder="Type part of a name">
    </label>
    <div id="friend-loading" class="locate-loading" style="display:none;">
      <span class="spinner"></span>
      <span>Searching...</span>
    </div>
    <div class="friend-list" id="friend-results" style="margin-top:12px;"></div>
  </div>
</div>
<div class="modal" id="member-profile-modal" role="dialog" aria-modal="true" aria-labelledby="member-profile-title">
  <div class="modal-box member-profile-box">
    <div class="modal-head">
      <strong id="member-profile-title">User Profile</strong>
      <button class="window-close" id="member-profile-close" type="button" aria-label="Close User Profile">×</button>
    </div>
    <div class="member-profile-status" id="member-profile-status" role="status" aria-live="polite">Loading profile...</div>
    <article id="member-profile-content" hidden>
      <section class="member-profile-identity" aria-label="Member identity">
        <div id="member-profile-avatar"></div>
        <div>
          <h2 id="member-profile-display-name"></h2>
        <div class="minor" id="member-profile-username" hidden></div>
        </div>
      </section>
      <dl class="member-profile-fields" id="member-profile-fields"></dl>
      <section class="member-profile-history" aria-labelledby="member-profile-history-title">
        <h3 id="member-profile-history-title">Previous display names</h3>
        <ul id="member-profile-history-list"></ul>
      </section>
      <div class="member-profile-warning" id="member-profile-warning" role="note" hidden></div>
      <div class="member-profile-actions" id="member-profile-actions" aria-label="Member actions"></div>
    </article>
  </div>
</div>
<section class="game-start-menu" id="game-start-menu" role="dialog" aria-modal="false" aria-labelledby="game-picker-title" hidden>
  <header class="game-picker-head" data-game-picker-drag-handle>
    <span class="game-picker-grip" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
    <div class="game-picker-title-group">
      <span class="game-picker-eyebrow">Room game library</span>
      <strong id="game-picker-title">Start a Game</strong>
    </div>
    <span class="game-picker-count" id="game-picker-count">0 installed games</span>
    <button class="window-close game-picker-close" id="game-picker-close" type="button" aria-label="Close game picker">&times;</button>
  </header>
  <div class="game-picker-controls">
    <label class="game-picker-search-wrap">
      <input id="game-picker-search" type="search" placeholder="Search installed games" aria-label="Search installed games" autocomplete="off">
    </label>
    <div class="game-picker-filters" aria-label="Game categories">
      <button class="game-picker-filter is-active" type="button" data-game-filter="all" aria-pressed="true">All</button>
      <button class="game-picker-filter" type="button" data-game-filter="card" aria-pressed="false">Card Games</button>
      <button class="game-picker-filter" type="button" data-game-filter="table" aria-pressed="false">Table Games</button>
      <button class="game-picker-filter" type="button" data-game-filter="arcade" aria-pressed="false">Arcade</button>
    </div>
  </div>
  <div class="game-picker-browser" id="game-picker-catalog"></div>
  <div class="game-picker-empty" id="game-picker-empty" hidden>No installed games match that search.</div>
  <div class="game-picker-bottom-grip" data-game-picker-drag-handle role="button" tabindex="0" aria-label="Drag game picker from bottom" title="Drag popup or double-click to center"></div>
  <footer class="game-picker-footer">
    <?php if (($user['role'] ?? '') === 'admin'): ?><a class="btn" href="game_review.php" target="_blank" rel="noopener">Game review</a><?php endif; ?>
    <div class="game-picker-selection" aria-live="polite">
      <span>Selected game</span>
      <strong id="game-picker-selection">No game selected</strong>
    </div>
    <button class="btn" id="game-picker-center" type="button">Center</button>
    <button class="btn" id="game-picker-cancel" type="button">Cancel</button>
    <button class="btn btn-primary" id="game-picker-continue" type="button" disabled>Continue</button>
  </footer>
</section>
<section class="game-start-menu room-games-menu" id="room-games-menu" role="dialog" aria-modal="false" aria-labelledby="room-games-title" hidden>
  <header class="game-picker-head" data-room-games-drag-handle>
    <span class="game-picker-grip" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
    <div class="game-picker-title-group">
      <span class="game-picker-eyebrow">Games active in this room</span>
      <strong id="room-games-title">Room Games</strong>
    </div>
    <span class="game-picker-count" id="room-games-count">0 active games</span>
    <button class="window-close game-picker-close" id="room-games-close" type="button" aria-label="Close room games">&times;</button>
  </header>
  <div class="game-picker-browser room-games-browser">
    <div class="room-games-list" id="room-games-list"></div>
    <div class="game-picker-empty" id="room-games-empty" hidden>No games are active in this room.</div>
  </div>
  <div class="game-picker-bottom-grip" data-room-games-drag-handle role="button" tabindex="0" aria-label="Drag room games from bottom" title="Drag popup or double-click to center"></div>
  <footer class="game-picker-footer room-games-footer">
    <div class="game-picker-selection" aria-live="polite">
      <span>Current room activity</span>
      <strong id="room-games-summary">No active games</strong>
    </div>
    <button class="btn" id="room-games-center" type="button">Center</button>
    <button class="btn" id="room-games-dismiss" type="button">Close</button>
  </footer>
</section>
<div class="modal" id="game-mode-modal" aria-hidden="true">
  <form class="modal-box game-mode-box" id="game-mode-form" role="dialog" aria-modal="true" aria-labelledby="game-mode-title">
    <div class="modal-head">
      <strong id="game-mode-title">Start a Game</strong>
      <button class="window-close" id="game-mode-close" type="button" aria-label="Cancel starting game">&times;</button>
    </div>
    <p class="settings-choice-description" id="game-mode-description">Choose how this game will record randomness and results.</p>
    <fieldset class="game-mode-choices" aria-describedby="game-mode-description">
      <legend class="sr-only">Game mode</legend>
      <label class="game-mode-choice">
        <input type="radio" name="game_mode" value="practice" checked>
        <span><strong>Practice Mode</strong><small>Play without ranked or recorded results. Each game follows its own published randomness rules.</small></span>
      </label>
      <label class="game-mode-choice" id="game-mode-recorded-choice">
        <input type="radio" name="game_mode" value="recorded">
        <span><strong>Ranked or Recorded Play</strong><small>Two to ten players use server-generated randomness, accepted locked settings, and authoritative results.</small></span>
      </label>
    </fieldset>
    <section class="game-mode-rules" id="game-mode-rules" aria-labelledby="game-mode-rules-title" hidden>
      <div class="settings-choice-name" id="game-mode-rules-title">Game Rules</div>
      <p class="settings-choice-description" id="game-mode-rules-description"></p>
      <div class="game-mode-rule-controls" id="game-mode-rule-controls"></div>
    </section>
    <div class="shared-form-actions">
      <button class="btn" id="game-mode-cancel" type="button">Cancel</button>
      <button class="btn btn-primary" type="submit">Continue</button>
    </div>
    <div class="admin-form-status" id="game-mode-status" role="status" aria-live="polite"></div>
  </form>
</div>

<div class="modal" id="game-records-modal" aria-hidden="true">
  <section class="modal-box game-records-box" role="dialog" aria-modal="true" aria-labelledby="game-records-title">
    <div class="modal-head">
      <strong id="game-records-title">Game Records</strong>
      <button class="window-close" id="game-records-close" type="button" aria-label="Close Game Records">&times;</button>
    </div>
    <div id="game-records-content"></div>
    <div class="admin-form-status" id="game-records-status" role="status" aria-live="polite"></div>
  </section>
</div>
<div class="modal" id="shared-attachments-modal" role="dialog" aria-modal="true" aria-labelledby="shared-attachments-title" aria-hidden="true">
  <div class="modal-box shared-attachments-box">
    <div class="modal-head">
      <strong id="shared-attachments-title">Shared Attachments</strong>
      <button class="window-close" id="shared-attachments-close" type="button" aria-label="Close Shared Attachments">&times;</button>
    </div>
    <nav class="shared-attachments-tabs" aria-label="Shared attachment views">
      <button class="btn active" type="button" data-shared-attachments-view="room" aria-current="page">This Room</button>
      <button class="btn" type="button" data-shared-attachments-view="community">Community</button>
      <button class="btn" type="button" data-shared-attachments-view="my-uploads">My Uploads</button>
    </nav>
    <div class="shared-attachments-list" id="shared-attachments-list" role="list"></div>
    <div class="admin-form-status" id="shared-attachments-status" role="status" aria-live="polite"></div>
  </div>
</div>
<div class="modal" id="p2p-transfer-compose-modal" role="dialog" aria-modal="true" aria-labelledby="p2p-transfer-compose-title" aria-hidden="true">
  <form class="modal-box p2p-transfer-box" id="p2p-transfer-compose-form">
    <div class="modal-head">
      <strong id="p2p-transfer-compose-title">Send File or Gesture</strong>
      <button class="window-close" id="p2p-transfer-compose-close" type="button" aria-label="Cancel transfer">&times;</button>
    </div>
    <p class="p2p-transfer-recipient">To <strong id="p2p-transfer-recipient-name">participant</strong></p>
    <div class="settings-choice-row p2p-transfer-choice-row">
      <span class="settings-choice-name" id="p2p-transfer-kind-label">What do you want to send?</span>
      <fieldset class="segmented-radio p2p-transfer-kind" aria-labelledby="p2p-transfer-kind-label">
        <label><input type="radio" name="transfer_kind" value="file" checked><span>File</span></label>
        <label><input type="radio" name="transfer_kind" value="gesture"><span>Gesture</span></label>
        <label><input type="radio" name="transfer_kind" value="avatar"><span>Avatar</span></label>
      </fieldset>
    </div>
    <div id="p2p-transfer-file-wrap" class="p2p-transfer-picker">
      <label>Choose files<input id="p2p-transfer-file" type="file" multiple></label>
      <label>Choose a folder<input id="p2p-transfer-folder" type="file" webkitdirectory multiple></label>
      <div id="p2p-transfer-drop-zone" class="p2p-transfer-drop-zone" role="button" tabindex="0" aria-describedby="p2p-transfer-drop-help">Drag files or a folder here</div>
      <p id="p2p-transfer-drop-help" class="minor">Use the file or folder picker for the equivalent keyboard and touch option.</p>
      <div id="p2p-transfer-manifest" class="p2p-transfer-manifest" aria-live="polite"></div>
    </div>
    <label id="p2p-transfer-gesture-wrap" hidden>Choose a gesture<select id="p2p-transfer-gesture"><option value="">Choose from your available gestures</option></select></label>
    <label id="p2p-transfer-avatar-wrap" hidden>Choose one avatar<input id="p2p-transfer-avatar" type="file" accept="image/jpeg,image/png,image/gif,image/webp"></label>
    <div class="settings-choice-row p2p-transfer-choice-row">
      <span class="settings-choice-name" id="p2p-transfer-delivery-label">Delivery</span>
      <fieldset class="segmented-radio segmented-radio-wide p2p-transfer-delivery" aria-labelledby="p2p-transfer-delivery-label">
        <label><input type="radio" name="transfer_delivery" value="p2p" checked><span>Peer-to-peer</span></label>
        <label><input type="radio" name="transfer_delivery" value="server"><span>Store on this server</span></label>
      </fieldset>
    </div>
    <p class="p2p-transfer-warning" id="p2p-transfer-warning" role="note"></p>
    <p class="server-upload-notice" id="p2p-server-upload-notice" role="note" hidden>Server upload notice: Files stored by this community may be accessed and reviewed by its administrators to investigate abuse, enforce community rules, and address safety or legal concerns. Use peer-to-peer transfer if you do not want the file stored on this server.</p>
    <div class="shared-form-actions">
      <button class="btn btn-primary" type="submit">Send Offer</button>
      <button class="btn" id="p2p-transfer-compose-cancel" type="button">Cancel</button>
    </div>
    <div class="admin-form-status" id="p2p-transfer-compose-status" role="status" aria-live="polite"></div>
  </form>
</div>
<div class="modal" id="p2p-transfer-offer-modal" role="dialog" aria-modal="true" aria-labelledby="p2p-transfer-offer-title" aria-hidden="true">
  <div class="modal-box p2p-transfer-box">
    <div class="modal-head"><strong id="p2p-transfer-offer-title" tabindex="-1">Incoming transfer</strong></div>
    <dl class="p2p-transfer-offer-facts" id="p2p-transfer-offer-facts"></dl>
    <p class="p2p-transfer-warning" id="p2p-transfer-offer-warning" role="note"></p>
    <div class="p2p-transfer-preview" id="p2p-transfer-offer-preview" role="status" aria-live="polite" hidden></div>
    <label class="p2p-transfer-direct-disk" id="p2p-transfer-direct-disk-wrap" hidden>
      <input id="p2p-transfer-direct-disk" type="checkbox">
      <span><strong>Download directly to this device</strong><br><small>This P2P-only option requires this tab to remain open and cannot resume after refresh, browser closure, or crash.</small></span>
    </label>
    <div class="shared-form-actions">
      <button class="btn" id="p2p-transfer-preview-request" type="button" hidden>Request safe preview</button>
      <button class="btn btn-primary" id="p2p-transfer-accept" type="button">Accept</button>
      <button class="btn" id="p2p-transfer-decline" type="button">Decline</button>
    </div>
    <div class="admin-form-status" id="p2p-transfer-offer-status" role="status" aria-live="polite"></div>
  </div>
</div>
<aside class="transfers-tray" id="transfers-tray" aria-labelledby="transfers-tray-title" hidden>
  <div class="transfers-tray-head"><strong id="transfers-tray-title">Transfers</strong><button id="transfers-tray-close" type="button" aria-label="Close Transfers">&times;</button></div>
  <p class="minor">Direct transfers continue in the background until their fixed deadline when the required local state remains available.</p>
  <section class="p2p-transfer-status-drawer" id="p2p-transfer-status-drawer" aria-label="Direct transfer status" aria-live="polite"></section>
</aside>
<?php if ($innerTranquillityPlayer['available']): ?>
<script src="<?= e($innerTranquillityPlayer['assets']['jquery']) ?>"></script>
<script src="<?= e($innerTranquillityPlayer['assets']['player']) ?>"></script>
<?php endif; ?>
<script src="https://www.youtube.com/iframe_api"></script>

<script src="<?= e(app_url('/assets/js/core/popup-behavior.js?v=20260919-modal-stack')) ?>"></script>
<script src="<?= e($roomAssetVersion('/assets/js/avatar-processing.js')) ?>"></script>
<script src="<?= e($roomAssetVersion('/assets/js/core/recent-authentication.js')) ?>"></script>
<script src="<?= e($roomAssetVersion('/assets/js/profile-relationship.js')) ?>"></script>
<script src="<?= e($roomAssetVersion('/assets/js/room.js')) ?>"></script>
<?php if ($canvasAvailable): ?><script type="module" src="<?= e($roomAssetVersion('/extensions/canvas/assets/canvas.js')) ?>"></script><?php endif; ?>
</body>
</html>
