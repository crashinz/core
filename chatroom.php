<?php
require_once __DIR__ . '/includes/base.php';
require_once __DIR__ . '/includes/inner_tranquillity_player_capability.php';
require_once __DIR__ . '/includes/runtime_diagnostics_capability.php';
$user = require_user();
$pdo = db();
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
$activeEjection = active_room_ejection($pdo, $roomId, (int)$user['id']);
if ($activeEjection) {
    $_SESSION['room_ejection_notice'] = [
        'permanent' => (bool)$activeEjection['permanent'],
        'duration_minutes' => $activeEjection['duration_minutes'] !== null ? (int)$activeEjection['duration_minutes'] : null,
    ];
    redirect_to('/lobby.php');
}
$session = active_session_for_room($pdo, $roomId);
$participant = participant_for_user($pdo, (int)$session['id'], $user);
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
touch_participant_presence($pdo, $participant, 1);
runtime_maintenance_for_session($pdo, (int)$session['id']);
$participant['webcam_path'] = null;
$participant['webcam_enabled'] = 0;

emit_event($pdo, (int)$session['id'], 'participant_join', array_merge([
    'id' => (int)$participant['id'],
    'user_id' => (int)$user['id'],
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
$csrfToken = csrf_token();
$innerTranquillityPlayer = inner_tranquillity_player_capability($room);
$runtimeDiagnostics = runtime_diagnostics_capability();
$roleColors = role_color_settings($pdo);
$roomAssetVersion = static function (string $path): string {
    $absolutePath = __DIR__ . $path;
    $version = is_file($absolutePath) ? (string)filemtime($absolutePath) : (string)time();
    return app_url($path) . '?v=' . rawurlencode($version);
};

// Release the session lock before client runtime APIs begin polling this room.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($room['name']) ?> - ChatSpace CE</title>
  <link rel="stylesheet" href="<?= e($roomAssetVersion('/assets/css/styles.css')) ?>">
  <?php if ($innerTranquillityPlayer['available']): ?>
  <link rel="stylesheet" href="<?= e($innerTranquillityPlayer['assets']['css']) ?>">
  <?php endif; ?>
</head>
<body data-room-id="<?= e($room['public_id']) ?>" data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e($csrfToken) ?>" data-role-colors-mode="<?= e($roleColors['mode']) ?>" style="<?= e(role_color_css_variables($pdo)) ?>" data-inner-tranquillity-player-relevant="<?= $innerTranquillityPlayer['relevant'] ? 'true' : 'false' ?>" data-inner-tranquillity-player-available="<?= $innerTranquillityPlayer['available'] ? 'true' : 'false' ?>" data-inner-tranquillity-player-reason="<?= e($innerTranquillityPlayer['reason']) ?>" data-runtime-diagnostics-enabled="<?= $runtimeDiagnostics['enabled'] ? 'true' : 'false' ?>" data-runtime-diagnostics-mode="<?= e($runtimeDiagnostics['mode']) ?>" data-runtime-verification-controls="<?= $runtimeDiagnostics['verification_controls'] ? 'true' : 'false' ?>">
<div class="room-layout">
  <div class="version-banner" id="version-banner" hidden>
    <span id="version-banner-text">A new ChatSpace version is available.</span>
    <button class="btn btn-aqua" id="version-refresh" type="button">Refresh</button>
  </div>
  <main class="main">
    <section class="room-stage" id="room-stage" tabindex="0" aria-label="Room stage; scroll to view larger avatar relationships">
      <div class="room-stage-viewport" id="room-stage-viewport">
        <?php $roomBgTiled = !empty($room['import_url']) && !empty($room['background_path']) && !str_starts_with((string)$room['background_mime'], 'video/'); ?>
        <div class="room-bg<?= $roomBgTiled ? ' room-bg-tiled' : '' ?>" <?php if ($room['background_path'] && !str_starts_with((string)$room['background_mime'], 'video/')): ?>style="background-image:url('<?= e(media_url($room['background_path'])) ?>')"<?php endif; ?>>
          <?php if ($room['background_path'] && str_starts_with((string)$room['background_mime'], 'video/')): ?>
          <video class="smart-bg-video" autoplay muted playsinline preload="auto"><source src="<?= e(media_url($room['background_path'])) ?>" type="<?= e($room['background_mime']) ?>"></video>
          <?php endif; ?>
        </div>
        <div class="vp-room-layout" id="vp-room-layout" hidden></div>
        <div class="game-stage-layer" id="game-stage" hidden>
        <div class="game-stage-head">
          <div>
            <div class="side-title">Game</div>
            <strong class="game-stage-title"><img id="game-stage-icon" src="" alt="" hidden><span id="game-stage-title">Game</span></strong>
          </div>
          <div class="game-stage-actions">
            <button class="btn" id="game-rematch" type="button">Play Again</button>
            <button class="btn btn-danger" id="game-resign" type="button">Resign</button>
            <button class="window-close" id="game-close" type="button" aria-label="Close game">×</button>
          </div>
        </div>
        <div class="game-stage-body">
          <aside class="game-player-card" id="game-player-one">
            <img src="<?= e(app_url('/assets/images/baghead.png')) ?>" alt="">
            <strong>Waiting</strong>
            <span class="minor">Player 1</span>
            <span class="game-typing-pill">typing...</span>
          </aside>
          <div class="game-frame-wrap">
            <iframe id="game-frame" title="Game"></iframe>
          </div>
          <aside class="game-player-card" id="game-player-two">
            <img src="<?= e(app_url('/assets/images/baghead.png')) ?>" alt="">
            <strong>Waiting</strong>
            <span class="minor">Player 2</span>
            <span class="game-typing-pill">typing...</span>
          </aside>
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
        </div>
        <input class="hidden-file-input" id="chat-file-input" type="file" accept="image/*,.pdf,.doc,.docx,.rtf,.txt,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,application/rtf">
        <textarea id="chat-input" maxlength="1000" rows="1" autocomplete="off" placeholder="Message <?= e($room['name']) ?>"></textarea>
        <div class="composer-actions">
          <span class="char-counter" id="char-counter">0/1000</span>
          <button class="composer-icon-btn emoji-btn" id="emoji-btn" type="button" aria-label="Media palette"><img src="<?= e(app_url('/assets/images/input-emoji.png')) ?>" alt=""></button>
          <button class="send-btn" type="submit" aria-label="Send message"><img src="<?= e(app_url('/assets/images/input-send.png')) ?>" alt=""></button>
        </div>
      </form>
      <div class="chat-tabs" id="chat-tabs">
        <button class="chat-tab active" type="button" data-chat-tab="room"><img src="<?= e(app_url('/assets/images/chat-pane-bubble.png')) ?>" alt=""> <span>Chat Room</span><span class="tab-badge" hidden>0</span></button>
        <button class="chat-tab" type="button" data-chat-tab="community"><img src="<?= e(app_url('/assets/images/chat-pane-community.png')) ?>" alt=""> <span>Community Chat</span><span class="tab-badge" hidden>0</span></button>
        <span id="link-tabs"></span>
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
        <button class="btn icon-label game-start-btn" id="game-start-btn" type="button"><img src="<?= e(app_url('/assets/images/games-icon.png')) ?>" alt="">Start a Game</button>
        <div class="game-start-menu" id="game-start-menu" hidden>
          <button data-game="chess" type="button"><img src="<?= e(app_url('/assets/images/chess-icon.png')) ?>" alt="">Chess</button>
          <button data-game="checkers" type="button"><img src="<?= e(app_url('/assets/images/checkers-icon.png')) ?>" alt="">Checkers</button>
          <button data-game="backgammon" type="button"><img src="<?= e(app_url('/assets/images/backgammon-icon.png')) ?>" alt="">Backgammon</button>
          <button data-game="spaceinvasion" type="button"><img src="<?= e(app_url('/assets/images/spaceinvasion-icon.png')) ?>" alt="">Space Invasion</button>
          <button data-game="tetris" type="button"><img src="<?= e(app_url('/assets/images/tetris-icon.png')) ?>" alt="">Tetris Versus</button>
        </div>
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
        <button class="btn btn-voice" id="voice-toggle" type="button">Join Voice</button>
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
    <div class="voice-device-actions">
      <button class="btn btn-primary" id="voice-device-join" type="submit">Join Voice</button>
      <button class="btn" id="voice-device-refresh" type="button">Allow microphone &amp; refresh</button>
      <button class="btn" id="voice-device-cancel" type="button">Cancel</button>
    </div>
    <div class="admin-form-status" id="voice-device-status" aria-live="polite"></div>
  </form>
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
      <button class="btn btn-danger" id="room-delete-open" type="button">Delete Room</button>
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
        <h2 id="avatar-size-title">Maximum Avatar Display Size</h2>
        <div class="minor" id="avatar-size-cap"></div>
      </div>
      <button class="icon-btn" id="avatar-size-close" type="button" aria-label="Close">&times;</button>
    </div>
    <section id="avatar-size-avatar-fields">
      <p class="minor">Sets the largest displayed width or height of your avatar. The original aspect ratio is preserved.</p>
      <label>Maximum edge
        <span class="avatar-size-input-with-unit"><input id="avatar-size-edge" name="avatar_display_size_px" type="number" min="42" step="1" inputmode="numeric"><span>px</span></span>
      </label>
      <div class="avatar-size-readout" id="avatar-size-current"></div>
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
      <label class="avatar-size-lock"><input id="avatar-size-aspect-lock" type="checkbox" checked> Lock aspect ratio</label>
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
  <button id="ctx-change-avatar" type="button">Change Avatar</button>
  <button id="ctx-avatar-size" type="button">Maximum Avatar Display Size</button>
  <div class="ctx-submenu-wrap" id="ctx-orientation-wrap">
    <button id="ctx-orientation" type="button" aria-haspopup="menu" aria-expanded="false">Orientation <span>&gt;</span></button>
    <div class="ctx-submenu" id="ctx-orientation-submenu" role="menu" aria-label="Avatar orientation">
      <button type="button" role="menuitemradio" data-avatar-orientation="original" data-label="Original">Original</button>
      <button type="button" role="menuitemradio" data-avatar-orientation="flip-horizontal" data-label="Flip Horizontally">Flip Horizontally</button>
      <button type="button" role="menuitemradio" data-avatar-orientation="flip-vertical" data-label="Flip Vertically">Flip Vertically</button>
      <button type="button" role="menuitemradio" data-avatar-orientation="flip-both" data-label="Flip Horizontally and Vertically">Flip Horizontally and Vertically</button>
    </div>
  </div>
  <button id="ctx-auras" type="button">Auras</button>
  <button id="ctx-toggle-webcam" type="button">Enable Webcam</button>
  <button id="ctx-webcam-size" type="button">Webcam Size</button>
  <button id="ctx-webcam-visibility" type="button">Hide this webcam for me</button>
  <button id="ctx-webcam-receive" type="button">Stop receiving this webcam</button>
  <button id="ctx-avatar-visibility" type="button">Hide this avatar until it changes</button>
  <button id="ctx-avatar-user-visibility" type="button">Hide avatars from this user</button>
  <button id="ctx-gesture-sender-visibility" type="button">Hide gesture media from this user</button>
  <button id="ctx-dm" type="button">Send DM</button>
  <button id="ctx-interact" type="button">Interact</button>
  <button id="ctx-lap-dance" type="button" aria-pressed="false">Start Lap Dance</button>
  <button id="ctx-lap-bounce" type="button" aria-pressed="false">Start Lap Bounce</button>
  <button id="ctx-block" class="danger" type="button">Block</button>
  <button id="ctx-unblock" type="button">Unblock</button>
  <button id="ctx-manage-relationship" type="button">Manage Relationship</button>
  <button id="ctx-unlink" class="danger" type="button">Unlink</button>
  <div class="ctx-divider" id="ctx-tools-divider"></div>
  <div class="ctx-submenu-wrap" id="ctx-tools-wrap">
    <button id="ctx-tools" type="button">Tools <span>›</span></button>
    <div class="ctx-submenu" id="ctx-tools-submenu">
      <button id="ctx-host-warn" type="button">Warn</button>
      <button id="ctx-host-kick" class="danger" type="button">Kick from Room</button>
      <button id="ctx-community-eject" class="danger" type="button">Community Eject</button>
    </div>
  </div>
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
  <button id="room-action-edit" type="button">Edit Room</button>
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
  <button id="lock-session-btn" type="button"><img src="<?= e(app_url('/assets/images/secure.png')) ?>" alt="">Lock Session</button>
  <button id="rooms-link" type="button" data-href="<?= e(app_url('/lobby.php')) ?>"><img src="<?= e(app_url('/assets/images/lobby.png')) ?>" alt="">Lobby</button>
  <button id="logout-link" type="button"><img src="<?= e(app_url('/assets/images/logout.png')) ?>" alt="">Log Out</button>
</div>
<form id="logout-form" method="post" action="<?= e(app_url('/logout.php')) ?>" hidden>
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
</form>
<div class="modal" id="chat-options-modal" aria-hidden="true">
  <div class="modal-box chat-options-box" role="dialog" aria-modal="true" aria-labelledby="chat-options-title">
    <div class="modal-head">
      <strong id="chat-options-title">Chat Options</strong>
      <button class="window-close" id="chat-options-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="settings-choice-row">
      <span class="settings-choice-name" id="chat-display-label">Message display</span>
      <fieldset class="segmented-radio" aria-labelledby="chat-display-label">
        <label><input type="radio" name="chat-display-mode" value="detailed"><span>Detailed</span></label>
        <label><input type="radio" name="chat-display-mode" value="compact"><span>Compact</span></label>
      </fieldset>
      <p class="settings-choice-description" id="chat-display-description"></p>
    </div>
    <section class="gesture-chat-options" aria-labelledby="gesture-chat-options-title">
      <div class="settings-choice-name" id="gesture-chat-options-title">Gestures</div>
      <label class="gesture-preference-row">
        <input id="gesture-show-animations" type="checkbox">
        <span><strong>Show gesture animations</strong><small>Show the current gesture animation in messages and avatar speech.</small></span>
      </label>
      <label class="gesture-preference-row">
        <input id="gesture-show-text" type="checkbox">
        <span><strong>Show gesture text</strong><small>Show canonical text in the format (Gesture) Gesture Text.</small></span>
      </label>
      <label class="gesture-preference-row">
        <input id="gesture-play-sounds" type="checkbox">
        <span><strong>Play gesture sounds</strong><small>Allow the current supported gesture-sound path to begin playback.</small></span>
      </label>
      <div class="gesture-preference-actions">
        <button class="btn" id="gesture-options-reset" type="button">Reset gesture options</button>
        <span class="minor" id="gesture-options-status" role="status" aria-live="polite"></span>
      </div>
    </section>
    <div class="settings-choice-row">
      <span class="settings-choice-name" id="webcam-visibility-label">Webcam display</span>
      <fieldset class="segmented-radio segmented-radio-wide" aria-labelledby="webcam-visibility-label">
        <label><input type="radio" name="webcam-visibility-mode" value="show"><span>Show webcams</span></label>
        <label><input type="radio" name="webcam-visibility-mode" value="hide"><span>Show avatars</span></label>
      </fieldset>
      <p class="settings-choice-description">Hiding webcams changes only what you see. Video may still be received.</p>
    </div>
    <div class="settings-choice-row">
      <span class="settings-choice-name" id="webcam-receive-label">Webcam receiving</span>
      <fieldset class="segmented-radio segmented-radio-wide" aria-labelledby="webcam-receive-label">
        <label><input type="radio" name="webcam-receive-mode" value="receive"><span>Receive video</span></label>
        <label><input type="radio" name="webcam-receive-mode" value="stop"><span>Stop receiving</span></label>
      </fieldset>
      <p class="settings-choice-description">Stops inbound webcam video while keeping voice available.</p>
    </div>
    <p class="settings-choice-description webcam-capability-notice" id="webcam-capability-notice" hidden>Webcam use is disabled for this installation.</p>
    <section class="hidden-avatar-options" aria-labelledby="hidden-avatar-options-title">
      <div class="settings-choice-name" id="hidden-avatar-options-title">Hidden Avatars</div>
      <p class="settings-choice-description">These choices affect only what you see.</p>
      <div id="hidden-avatar-list" class="hidden-avatar-list"></div>
      <div id="hidden-avatar-empty" class="minor">No avatars are hidden.</div>
      <button class="btn" id="hidden-avatar-show-all" type="button" hidden>Show all</button>
      <div class="hidden-avatar-confirm" id="hidden-avatar-confirm" hidden>
        <span>Show every avatar you have hidden?</span>
        <div>
          <button class="btn" id="hidden-avatar-show-all-cancel" type="button">Cancel</button>
          <button class="btn btn-primary" id="hidden-avatar-show-all-confirm" type="button">Show all</button>
        </div>
      </div>
      <div class="minor" id="hidden-avatar-status" role="status" aria-live="polite"></div>
    </section>
    <div class="chat-options-actions">
      <button class="btn" id="webcam-options-reset" type="button">Reset webcam options</button>
    </div>
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
      <div class="form-error" id="report-problem-status" role="status"></div>
      <div class="modal-actions"><button class="btn btn-primary" type="submit">Submit Report</button></div>
    </form>
  </div>
</div>
<div class="session-lock" id="session-lock" aria-hidden="true">
  <form class="session-lock-box" id="session-lock-form">
    <div class="session-lock-brand">
      <img src="<?= e(app_url('/assets/images/chatspace-ce-logo.png')) ?>" alt="">
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
      <button class="btn btn-primary" id="personal-gesture-create" type="button">Create / Edit Gestures</button>
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
  <div class="gesture-action-menu" id="gesture-action-menu" role="menu" hidden></div>
</div>
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
<?php if ($innerTranquillityPlayer['available']): ?>
<script src="<?= e($innerTranquillityPlayer['assets']['jquery']) ?>"></script>
<script src="<?= e($innerTranquillityPlayer['assets']['player']) ?>"></script>
<?php endif; ?>
<script src="https://www.youtube.com/iframe_api"></script>

<script src="<?= e($roomAssetVersion('/assets/js/avatar-processing.js')) ?>"></script>
<script src="<?= e($roomAssetVersion('/assets/js/room.js')) ?>"></script>
</body>
</html>
