<?php
require_once __DIR__ . '/includes/room_importer.php';
$user = require_user();
$pdo = db();
$branding = private_site_branding_projection($pdo, 'lobby');
$communityEjection = active_community_ejection($pdo, (int)$user['id']);
if ($communityEjection) {
    redirect_to('/community_ejected.php');
}
$ejectionNotice = $_SESSION['room_ejection_notice'] ?? null;
unset($_SESSION['room_ejection_notice']);
$lobbyError = null;
$canonicalAdminLaunch = (string)($_GET['admin'] ?? '') === '1';
$staffRoles = ['admin', 'moderator', 'developer'];
$isInstallationOwner = moderation_identity_is_owner($pdo, (int)$user['id']);
if ($canonicalAdminLaunch && !in_array($user['role'] ?? 'user', $staffRoles, true)) {
    http_response_code(403);
    exit('Authorized staff access is required.');
}
if (!$canonicalAdminLaunch) cleanup_stale_participants($pdo);
$roleColors = role_color_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    security_authorize_outside_content_or_json($pdo, $user, 'room_create', ['source' => 'lobby_form']);
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        try {
            $bgPath = null;
            $bgMime = null;
            $bgThumbPath = null;
            if (!empty($_FILES['background']['tmp_name']) && is_uploaded_file($_FILES['background']['tmp_name'])) {
                security_authorize_outside_content_or_json($pdo, $user, 'room_background_upload', ['source' => 'lobby_form']);
                $saved = save_room_background_upload($_FILES['background'], $_FILES['background_thumb'] ?? null);
                $bgPath = $saved['path'];
                $bgMime = $saved['mime'];
                $bgThumbPath = $saved['thumb_path'];
            }
            $stmt = $pdo->prepare('INSERT INTO rooms (public_id, owner_id, name, background_path, background_mime, background_thumb_path) VALUES (?,?,?,?,?,?)');
            $stmt->execute([uuid_v4(), (int)$user['id'], $name, $bgPath, $bgMime, $bgThumbPath]);
            active_session_for_room($pdo, (int)$pdo->lastInsertId());
            redirect_to('/lobby.php');
        } catch (RuntimeException $e) {
            $lobbyError = $e->getMessage();
        }
    }
}

$onlineCutoff = stale_cutoff($pdo);
$roomsStmt = $pdo->prepare(
    'SELECT r.*, u.display_name AS owner_name,
        (
          SELECT COUNT(DISTINCT p.user_id)
            FROM participants p
            JOIN room_sessions rs ON rs.id = p.session_id
           WHERE rs.room_id = r.id
             AND p.last_seen_at >= ?
        ) AS online_count
     FROM rooms r JOIN users u ON u.id = r.owner_id
     WHERE NOT EXISTS (
        SELECT 1 FROM room_ejections re
         WHERE re.room_id = r.id
           AND re.user_id = ' . (int)$user['id'] . '
           AND ' . active_ejection_sql('re') . '
     )
     ORDER BY r.created_at DESC'
);
$roomsStmt->execute([$onlineCutoff]);
$rooms = $roomsStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(branded_page_title('Lobby', $pdo, 'lobby')) ?></title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
</head>
<body data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>" data-is-admin="<?= ($user['role'] ?? '') === 'admin' ? 'true' : 'false' ?>" data-is-installation-owner="<?= $isInstallationOwner ? 'true' : 'false' ?>" data-canonical-admin-launch="<?= $canonicalAdminLaunch ? 'true' : 'false' ?>" data-role-colors-mode="<?= e($roleColors['mode']) ?>" style="<?= e(role_color_css_variables($pdo)) ?>">
<main class="picker-shell">
  <section class="picker-main">
    <div class="topbar">
      <div class="lobby-brand-block">
        <div class="app-title">
          <img class="<?= $branding['has_custom_logo'] ? 'custom-brand-logo' : '' ?>" src="<?= e(app_url($branding['compact_logo_path'])) ?>" alt="<?= e($branding['effective_name']) ?>">
          <div>
            <div class="app-name"><?= e($branding['effective_name'] === 'ChatSpace Community Edition' ? 'ChatSpace' : $branding['effective_name']) ?></div>
            <div class="app-edition"><?= $branding['effective_name'] !== 'ChatSpace Community Edition' ? 'Community powered by ChatSpace CE' : 'Community Edition' ?></div>
          </div>
        </div>
        <h1 class="picker-title">Lobby</h1>
      </div>
      <div class="top-actions lobby-account">
        <div class="minor lobby-signed-in">Signed in as <strong><?= e($user['display_name']) ?></strong></div>
        <button class="gear-btn lobby-gear" id="lobby-menu-btn" type="button" aria-label="Lobby menu">⚙</button>
      </div>
        <div id="lobby-menu">
          <?php if (in_array($user['role'] ?? 'user', ['admin', 'developer'], true)): ?>
        <button id="admin-open" type="button"><img src="<?= e(app_url('/assets/images/lobby.png')) ?>" alt="">Admin</button>
        <?php endif; ?>
        <a href="<?= e(app_url('/account.php?return=lobby')) ?>"><img src="<?= e(app_url('/assets/images/secure.png')) ?>" alt="">Account</a>
        <form class="menu-form" method="post" action="<?= e(app_url('/logout.php')) ?>">
          <?= csrf_input() ?>
          <button type="submit"><img src="<?= e(app_url('/assets/images/logout.png')) ?>" alt="">Log Out</button>
        </form>
      </div>
    </div>
    <div class="room-grid" id="room-grid">
      <form class="room-card create-room-tile" id="create-room-form" method="post" enctype="multipart/form-data">
        <?= csrf_input() ?>
        <div class="create-room-tile-inner">
          <h2>Create Room</h2>
          <?php if ($lobbyError): ?><div class="form-error"><?= e($lobbyError) ?></div><?php endif; ?>
          <div class="room-create-tabs" role="tablist" aria-label="Room creation options">
            <button class="room-create-tab active" type="button" data-create-tab="manual">Create</button>
            <button class="room-create-tab" type="button" data-create-tab="import">Import URL</button>
          </div>
          <div class="room-create-panel active" id="room-create-manual">
            <label>Room name<input name="name" required placeholder="Moonlit Study, Neon Lounge, Table 7..."></label>
            <label>Background image or video
              <span class="file-picker">
                <input id="room-background-input" type="file" name="background" accept="image/*,video/mp4,video/webm">
                <span class="file-picker-btn">Choose Background</span>
                <span class="file-picker-name" id="room-background-name">No file selected</span>
              </span>
              <span class="upload-progress" id="room-upload-progress" aria-live="polite">
                <span class="upload-progress-track"><span class="upload-progress-bar"></span></span>
                <span class="upload-progress-meta"><span class="upload-progress-msg">Waiting...</span><span class="upload-progress-pct">0%</span></span>
              </span>
            </label>
            <button class="btn btn-primary" type="submit">Create Room</button>
          </div>
          <div class="room-create-panel" id="room-create-import">
            <label>VP-style room URL<input id="room-import-url" type="url" placeholder="https://example.com/user/room.html"></label>
            <button class="btn btn-primary" id="room-import-preview" type="button">Preview Import</button>
            <div class="room-import-status" id="room-import-status" aria-live="polite"></div>
            <div class="room-import-preview" id="room-import-preview-card" hidden></div>
          </div>
        </div>
      </form>
      <?php foreach ($rooms as $room): ?>
      <article class="room-card" data-room-id="<?= e($room['public_id']) ?>">
        <?php
          $tileBg = $room['background_path'];
          if ($room['background_path'] && str_starts_with((string)$room['background_mime'], 'video/')) {
              $tileBg = $room['background_thumb_path'] ?: null;
          }
          if (!$tileBg) {
              $tileBg = room_import_tile_image_from_layout($room['import_layout_json'] ?? null);
          }
        ?>
        <div class="room-card-media" <?php if ($tileBg): ?>style="background-image:url('<?= e(media_url($tileBg)) ?>')"<?php endif; ?>>
          <?php if ($room['background_path'] && str_starts_with((string)$room['background_mime'], 'video/') && !$room['background_thumb_path']): ?>
          <div class="room-video-placeholder">Video Room</div>
          <?php endif; ?>
        </div>
        <div class="room-card-body">
          <h2 class="room-card-name"><?= e($room['name']) ?></h2>
          <div class="minor room-card-meta"><span class="room-card-count"><?= (int)$room['online_count'] ?></span> online · made by <span class="room-card-owner"><?= e($room['owner_name']) ?></span></div>
          <p class="room-card-actions">
            <a class="btn btn-primary" href="<?= e(app_url('/chatroom.php?id=' . rawurlencode((string)$room['public_id']))) ?>">Enter</a>
            <?php if ((int)$room['owner_id'] === (int)$user['id'] || in_array($user['role'] ?? 'user', ['admin', 'developer'], true)): ?>
            <button class="btn btn-primary room-edit-open" type="button" data-room-id="<?= e($room['public_id']) ?>" data-room-name="<?= e($room['name']) ?>" data-room-bg="<?= e($room['background_path'] ? media_url($room['background_path']) : '') ?>" data-room-thumb="<?= e($room['background_thumb_path'] ? media_url($room['background_thumb_path']) : '') ?>" data-room-mime="<?= e($room['background_mime'] ?? '') ?>">Edit</button>
            <?php endif; ?>
          </p>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php if ($branding['has_custom_logo']): ?>
      <div class="powered-by lobby-powered-by">
        <span>Powered by</span>
        <img src="<?= e(app_url($branding['powered_logo_path'])) ?>" alt="ChatSpace Community Edition">
      </div>
    <?php endif; ?>
  </section>
</main>
<div class="modal" id="lobby-room-edit-modal">
  <form class="modal-box" id="lobby-room-edit-form" enctype="multipart/form-data">
    <?= csrf_input() ?>
    <div class="modal-head">
      <strong>Edit Room</strong>
      <button class="window-close" id="lobby-room-edit-close" type="button" aria-label="Close">×</button>
    </div>
    <input type="hidden" id="lobby-room-edit-id" name="room_public_id">
    <div class="room-edit-preview" id="lobby-room-edit-preview"></div>
    <label>Room name<input id="lobby-room-edit-name" name="name" required></label>
    <label>Background image or video
      <span class="file-picker">
        <input id="lobby-room-edit-background" type="file" name="background" accept="image/*,video/mp4,video/webm">
        <span class="file-picker-btn">Choose Background</span>
        <span class="file-picker-name" id="lobby-room-edit-background-name">No file selected</span>
      </span>
      <span class="upload-progress" id="lobby-room-edit-upload-progress" aria-live="polite">
        <span class="upload-progress-track"><span class="upload-progress-bar"></span></span>
        <span class="upload-progress-meta"><span class="upload-progress-msg">Waiting...</span><span class="upload-progress-pct">0%</span></span>
      </span>
    </label>
    <div class="ejection-list-wrap">
      <div class="side-title">Kicked Users</div>
      <div class="ejection-list" id="lobby-room-ejection-list">Loading...</div>
    </div>
    <div class="room-edit-actions">
      <button class="btn btn-primary" type="submit">Save Room</button>
      <button class="btn btn-danger" id="lobby-room-delete-open" type="button">Delete Room</button>
    </div>
  </form>
</div>
<div class="modal" id="lobby-room-delete-modal">
  <div class="modal-box warning-box">
    <div class="modal-head">
      <strong>Delete Room</strong>
      <button class="window-close" id="lobby-room-delete-close" type="button" aria-label="Close">×</button>
    </div>
    <p>This will delete the room and eject anyone currently inside it.</p>
    <div class="password-actions">
      <button class="btn btn-danger" id="lobby-room-delete-confirm" type="button">Delete Room</button>
      <button class="btn" id="lobby-room-delete-cancel" type="button">Cancel</button>
    </div>
  </div>
</div>
<div class="lobby-toast" id="lobby-toast" hidden>
  <span>Aw snap, the room you were in was deleted.</span>
  <button class="window-close" id="lobby-toast-close" type="button" aria-label="Dismiss">×</button>
</div>
<?php if ($ejectionNotice): ?>
<div class="modal open" id="lobby-ejection-modal">
  <div class="modal-box warning-box">
    <div class="modal-head">
      <strong>Room Ejection</strong>
    </div>
    <div>
      <?php if (!empty($ejectionNotice['permanent'])): ?>
      You have been permanently ejected from the room.
      <?php else: ?>
      You have been ejected from the room for <?= (int)($ejectionNotice['duration_minutes'] ?? 0) ?> minutes.
      <?php endif; ?>
    </div>
    <button class="btn btn-aqua" id="lobby-ejection-understand" type="button" style="width:100%;margin-top:14px;">I understand</button>
  </div>
</div>
<?php endif; ?>
<?php if (false): // Dormant account modal markup; account.php is the current account-management surface. ?>
<div class="modal" id="password-modal">
  <form class="modal-box password-box" id="password-form">
    <?= csrf_input() ?>
    <div class="modal-head">
      <strong>Update Password</strong>
      <button class="window-close" id="password-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="password-status" id="password-status" aria-live="polite"></div>
    <label>Old password<input id="password-old" name="old_password" type="password" required autocomplete="current-password"></label>
    <label>New password<input id="password-new" name="new_password" type="password" required minlength="8" autocomplete="new-password"></label>
    <label>Confirm new password<input id="password-confirm" name="confirm_password" type="password" required minlength="8" autocomplete="new-password"></label>
    <div class="password-actions">
      <button class="btn btn-primary" type="submit">Update</button>
      <button class="btn" id="password-cancel" type="button">Cancel</button>
    </div>
  </form>
</div>
<div class="modal" id="recovery-modal">
  <div class="modal-box password-box">
    <div class="modal-head">
      <strong>Account Recovery</strong>
      <button class="window-close" id="recovery-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="password-status" id="recovery-status" aria-live="polite"></div>
    <div class="recovery-card" id="recovery-card">
      <div class="minor">Checking recovery status...</div>
    </div>
    <p class="minor">Copy your Lost Access recovery code to a safe place. It is used if you lose access to your account.</p>
    <div class="password-actions">
      <button class="btn btn-primary" id="recovery-generate" type="button">Create Recovery Code</button>
      <button class="btn" id="recovery-cancel" type="button">Cancel</button>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if (in_array($user['role'] ?? 'user', ['admin', 'developer'], true)): ?>
<div class="modal" id="admin-modal">
  <div class="modal-box admin-box">
    <div class="modal-head">
      <strong>Admin Dashboard</strong>
      <button class="window-close" id="admin-close" type="button" aria-label="Close">×</button>
    </div>
    <div class="admin-dashboard">
      <nav class="admin-nav" aria-label="Admin sections">
        <div class="admin-nav-label">Overview</div>
        <button class="admin-nav-item active" data-admin-section="overview" type="button">
          <img src="<?= e(app_url('/assets/images/chatspace-ce-logo.png')) ?>" alt=""> Dashboard
        </button>
        <div class="admin-nav-label">Users</div>
        <button class="admin-nav-item" data-admin-section="users" type="button">
          <img src="<?= e(app_url('/assets/images/locate.png')) ?>" alt=""> Manage Users
          <span class="admin-nav-count" id="admin-user-count">0</span>
        </button>
        <div class="admin-nav-label">System</div>
        <button class="admin-nav-item" data-admin-section="settings" type="button">
          <img src="<?= e(app_url('/assets/images/limits.png')) ?>" alt=""> Settings
        </button>
        <button class="admin-nav-item" data-admin-section="gestures" type="button">
          <span class="admin-nav-symbol" aria-hidden="true">G</span> Gestures
          <span class="admin-nav-count" id="admin-gesture-count">0</span>
        </button>
        <button class="admin-nav-item" data-admin-section="database" type="button">
          <img src="<?= e(app_url('/assets/images/sql-server.png')) ?>" alt=""> Database
        </button>
        <button class="admin-nav-item" data-admin-section="link-icons" type="button">
          <img src="<?= e(app_url('/assets/images/cs-icons/plus.png')) ?>" alt=""> Link Icons
          <span class="admin-nav-count" id="admin-link-icon-count">0</span>
        </button>
        <div class="admin-nav-label">Moderation</div>
        <button class="admin-nav-item" data-admin-section="moderation" type="button">
          <img src="<?= e(app_url('/assets/images/block-user.png')) ?>" alt=""> Actions
          <span class="admin-nav-count" id="admin-moderation-count">0</span>
        </button>
        <button class="admin-nav-item" data-admin-section="logs" type="button">
          <img src="<?= e(app_url('/assets/images/log-file.png')) ?>" alt=""> Tool Logs
          <span class="admin-nav-count" id="admin-log-count">0</span>
        </button>
        <button class="admin-nav-item" data-admin-section="errors" type="button">
          <img src="<?= e(app_url('/assets/images/log-file.png')) ?>" alt=""> Errors
          <span class="admin-nav-count" id="issue-count" aria-label="0 issues">0</span>
        </button>
      </nav>
      <div class="admin-main">
        <div class="admin-form-status" id="admin-canonical-status" role="status" aria-live="polite"></div>
        <section class="admin-section active" id="admin-section-overview">
          <div class="admin-section-title">Operator Overview</div>
          <div class="admin-section-sub">Quick status for accounts, enforcement, platform limits, and backup controls.</div>
          <div class="admin-summary-grid">
            <button class="admin-summary-card" type="button" data-admin-jump="users">
              <span>Users</span>
              <strong id="admin-summary-users">0</strong>
              <small>Manage accounts and roles</small>
            </button>
            <button class="admin-summary-card" type="button" data-admin-jump="moderation">
              <span>Moderation</span>
              <strong id="admin-summary-moderation">0</strong>
              <small>Blocks and active ejections</small>
            </button>
            <button class="admin-summary-card" type="button" data-admin-jump="settings">
              <span>Limits</span>
              <strong>GIF</strong>
              <small>Rate, upload, and GIF controls</small>
            </button>
            <button class="admin-summary-card" type="button" data-admin-jump="database">
              <span>Database</span>
              <strong>DB</strong>
              <small>Download or restore backups</small>
            </button>
          </div>
        </section>

        <section class="admin-section" id="admin-section-users">
          <div class="admin-section-title">Manage Users</div>
          <div class="admin-section-sub">Create accounts, reset passwords, and set account roles.</div>
          <?php if ($isInstallationOwner): ?>
          <div class="admin-panel admin-owner-policy-sections" aria-label="Installation Owner policy sections">
            <h3>Installation Owner</h3>
            <ul>
              <li>Security &amp; Privacy</li>
              <li>Staff &amp; Capabilities</li>
              <li>Network &amp; Trusted Proxies</li>
              <li>Ownership &amp; Recovery</li>
            </ul>
            <section id="admin-owner-transfer-panel" aria-labelledby="admin-owner-transfer-title">
              <h4 id="admin-owner-transfer-title">Ownership &amp; Recovery</h4>
              <p class="minor">Only the current Installation Owner may atomically transfer ownership to another Administrator. Both accounts remain Administrators. This does not delete, deactivate, or weaken either account.</p>
              <p class="minor" id="admin-owner-current" aria-live="polite">Loading current ownership...</p>
              <form id="admin-owner-transfer-form" class="admin-create">
                <?= csrf_input() ?>
                <label>New Installation Owner
                  <select name="new_owner_id" required></select>
                </label>
                <label>Reason
                  <textarea name="reason" minlength="3" maxlength="500" rows="3" required></textarea>
                </label>
                <button class="btn btn-primary" type="submit">Review Ownership Transfer</button>
                <div class="admin-form-status" id="admin-owner-transfer-status" aria-live="polite"></div>
                <section id="admin-owner-transfer-confirmation" class="settings-impact-confirmation" aria-live="assertive" hidden>
                  <h4>Confirm Installation Owner transfer</h4>
                  <p id="admin-owner-transfer-preview"></p>
                  <div class="shared-form-actions">
                    <button class="btn btn-danger" id="admin-owner-transfer-confirm" type="button">Confirm Transfer</button>
                    <button class="btn" id="admin-owner-transfer-cancel" type="button">Cancel</button>
                  </div>
                </section>
              </form>
            </section>
          </div>
          <div class="admin-panel" id="admin-network-privacy-panel">
            <h3>Network &amp; Trusted Proxies</h3>
            <p class="minor">HTTPS enforcement is mandatory. Forwarded addresses and protocol are trusted only from the private trusted-proxy list. Saved addresses remain masked.</p>
            <form id="admin-network-policy-form">
              <?= csrf_input() ?>
              <div class="admin-create">
                <label><input id="admin-network-hsts" type="checkbox"> Deployment has verified HSTS readiness</label>
                <label><input id="admin-network-exact-enabled" type="checkbox"> Enable owner-only Exact IP Access</label>
                <label>Default reveal duration (1–60 minutes)
                  <input id="admin-network-reveal-default" type="number" min="1" max="60" value="5" required>
                </label>
                <label>Replace trusted proxy IP/CIDR list
                  <textarea id="admin-network-trusted-proxies" rows="4" placeholder="One complete IP or CIDR per line"></textarea>
                </label>
                <p class="minor" id="admin-network-trusted-proxies-current">No trusted proxies are configured.</p>
                <button class="btn btn-primary" type="submit">Save Network Policy</button>
                <div class="admin-form-status" id="admin-network-policy-status" aria-live="polite"></div>
              </div>
            </form>
            <hr>
            <h4>Exact IP Access</h4>
            <p class="minor">Ordinary views use opaque identifiers. Reveals require recent authentication, a reason, and a non-extendable lease. Exact addresses are never written to Tool Logs.</p>
            <div class="admin-create">
              <label>Opaque network identifier
                <input id="admin-network-opaque-id" readonly>
              </label>
              <label>Reason
                <textarea id="admin-network-reveal-reason" maxlength="500" rows="3"></textarea>
              </label>
              <label>Duration
                <input id="admin-network-reveal-minutes" type="number" min="1" max="60" value="5">
              </label>
              <div class="shared-form-actions">
                <button class="btn btn-danger" id="admin-network-reveal" type="button">Reveal Exact IP</button>
                <button class="btn" id="admin-network-hide" type="button">Hide now</button>
              </div>
              <output id="admin-network-reveal-output" aria-live="assertive">Exact IP hidden.</output>
            </div>
          </div>
          <div class="admin-panel" id="admin-retention-panel">
            <h3>Retention and Account-Lifecycle Foundations</h3>
            <p class="minor">Configure message and resolved-report evidence retention. Open reports and safety holds override expiry. Account deletion is unavailable in Build 000051.</p>
            <form id="admin-retention-form" class="admin-create">
              <?= csrf_input() ?>
              <label>Data class
                <select name="domain">
                  <option value="room-community">Room and Community messages</option>
                  <option value="dm">Direct messages</option>
                  <option value="relationship">Relationship chat</option>
                  <option value="game">Game chat</option>
                  <option value="resolved-report-evidence">Resolved report evidence</option>
                </select>
              </label>
              <label>Retention days
                <input name="days" type="number" min="1" max="3650" value="30" required>
              </label>
              <label><input name="keep_forever" type="checkbox"> Keep forever</label>
              <button class="btn btn-primary" type="submit">Preview Change</button>
              <div class="admin-form-status" id="admin-retention-status" aria-live="polite"></div>
              <section id="admin-retention-confirmation" class="settings-impact-confirmation" aria-live="assertive" hidden>
                <h4>Review retention change</h4>
                <p id="admin-retention-preview"></p>
                <p id="admin-retention-backup-disclosure"></p>
                <div class="shared-form-actions">
                  <button class="btn btn-danger" id="admin-retention-confirm" type="button">Confirm and Apply</button>
                  <button class="btn" id="admin-retention-cancel" type="button">Cancel</button>
                </div>
              </section>
            </form>
            <div id="admin-retention-policies" class="admin-scroll-list" aria-live="polite"></div>
            <p class="minor">Non-destructive suspension and session revocation remain distinct from future Delete Account execution. Ownership transfer is required before any future irreversible lifecycle action.</p>
          </div>
          <?php endif; ?>
          <div class="admin-panel">
            <form class="admin-create" id="admin-create">
              <?= csrf_input() ?>
              <input name="username" placeholder="Username" required minlength="3" maxlength="32" pattern="[a-z0-9][a-z0-9_.\x2d]{2,31}">
              <input name="display_name" placeholder="Display name (optional)">
              <input name="email" type="email" placeholder="Email" required>
              <input name="password" type="password" placeholder="Password" required>
              <select name="role">
                <option value="user">User</option>
                <option value="moderator">Moderator</option>
                <option value="guide">Guide</option>
                <option value="developer">Developer</option>
                <option value="admin">Admin</option>
              </select>
              <button class="btn btn-primary" type="submit">Add User</button>
              <div class="admin-form-status" aria-live="polite"></div>
            </form>
          </div>
          <div class="admin-panel">
            <div class="admin-users admin-scroll-list" id="admin-users"></div>
          </div>
          <div class="admin-panel">
            <h3>Trusted Review, Capability Requests, and Appeals</h3>
            <p class="minor">Select all only selects items for review; it never grants a capability. Public reasons are shown to the member. Internal notes remain private.</p>
            <div class="admin-users admin-scroll-list" id="admin-moderation-cases"></div>
          </div>
          <div class="admin-panel">
            <h3>Moderation — Users</h3>
            <form id="admin-moderation-user-search" class="admin-create">
              <input name="search" type="search" placeholder="Search online or offline users">
              <select name="sort"><option value="name">Name</option><option value="newest">Newest</option><option value="trust">Trust state</option></select>
              <select name="per_page"><option value="25">25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select>
              <button class="btn" type="submit">Search</button>
            </form>
            <div class="admin-users admin-scroll-list" id="admin-moderation-users"></div>
            <div class="shared-form-actions"><button class="btn" id="admin-moderation-prev" type="button">Previous</button><span id="admin-moderation-page">Page 1</span><button class="btn" id="admin-moderation-next" type="button">Next</button></div>
          </div>
        </section>

        <section class="admin-section" id="admin-section-settings">
          <div class="admin-section-title">Settings</div>
          <div class="admin-section-sub">Search and manage installation policy through the shared Setup/Admin registry.</div>
          <div class="admin-panel settings-registry-shell" data-settings-scroll-owner tabindex="0" role="region" aria-label="Complete Admin installation settings">
            <div class="settings-registry-heading">
              <div><h3>Installation Settings</h3><p class="minor">Persistence remains with each authoritative policy owner.</p></div>
              <div class="settings-registry-state" id="lobby-admin-settings-compatibility-state" aria-live="polite">Loading settings…</div>
            </div>
            <div id="lobby-admin-settings-unlock"></div>
            <section class="branding-license-authority" aria-labelledby="admin-branding-license-title">
              <div class="branding-license-actions">
                <a class="btn btn-primary branding-license-link" href="<?= e(app_url('/license.php')) ?>" id="admin-branding-license-title">View original License</a>
                <a class="btn branding-license-link" href="<?= e(app_url('/changelog.php')) ?>">View exe's Changelog</a>
              </div>
              <div class="branding-license-reminder-card">
                <h4>Branding and License Reminder</h4>
                <p data-branding-reminder-authority><?= e(PRIVATE_SITE_BRANDING_REMINDER_DEFAULT) ?></p>
                <button class="btn" type="button" data-edit-branding-reminder>Edit reminder wording</button>
              </div>
            </section>
            <div class="settings-registry-toolbar" role="search">
              <label>Search settings<input id="lobby-admin-settings-search" type="search" autocomplete="off" placeholder="Label, help, category, alias, or setting ID"></label>
              <label>Filter<select id="lobby-admin-settings-filter"><option value="all">All</option><option value="enabled">Enabled</option><option value="disabled">Disabled</option><option value="changed">Changed from default</option><option value="original">Original-author compatibility relevant</option></select></label>
            </div>
            <div class="settings-registry-actions">
              <button class="btn" id="lobby-admin-settings-original-preview" type="button">Review Original-compatible Changes</button>
              <button class="btn" id="lobby-admin-settings-framework-preview" type="button">Review Framework Defaults</button>
              <button class="btn btn-danger" id="lobby-admin-settings-reset-optional" type="button">Reset All Optional Settings</button>
            </div>
            <div id="lobby-admin-settings-preset-review" class="settings-preset-review" hidden></div>
            <form class="settings-registry-form" id="lobby-admin-settings-registry-form">
              <?= csrf_input() ?>
              <div id="lobby-admin-settings-registry" class="settings-registry" aria-live="polite"></div>
              <section id="lobby-admin-profile-limit-confirmation" class="settings-impact-confirmation" aria-live="assertive" hidden>
                <h4>Confirm profile-limit impact</h4>
                <p>Existing profile values will not be truncated or rewritten. The following records will remain above the proposed limit and may be retained unchanged.</p>
                <ul id="lobby-admin-profile-limit-impact-list"></ul>
                <div class="shared-form-actions">
                  <button class="btn btn-primary" id="lobby-admin-profile-limit-confirm" type="button">Confirm Lower Limits</button>
                  <button class="btn" id="lobby-admin-profile-limit-cancel" type="button">Cancel</button>
                </div>
              </section>
              <section id="lobby-admin-database-compatibility-confirmation" class="settings-impact-confirmation" aria-live="assertive" hidden>
                <h4>Confirm compatibility-enforcement risk</h4>
                <p>
                  Disabling proactive compatibility enforcement allows ordinary runtime to be attempted without first
                  proving that the deployed application release and configured database match. A genuine mismatch may
                  surface as an ordinary PHP, query, or feature failure. This does not disable recovery maintenance,
                  authentication, authorization, privacy, validation, or any unrelated security safeguard.
                </p>
                <div class="shared-form-actions">
                  <button class="btn btn-danger" id="lobby-admin-database-compatibility-confirm" type="button">Confirm Disable</button>
                  <button class="btn" id="lobby-admin-database-compatibility-cancel" type="button">Cancel</button>
                </div>
              </section>
              <section id="lobby-admin-moderation-trust-confirmation" class="settings-impact-confirmation" aria-live="assertive" hidden>
                <h4>Confirm Moderation and Trust impact</h4>
                <p id="lobby-admin-moderation-trust-impact">
                  Disabling optional Moderation and Trust workflows stops active optional requests and approvals while
                  preserving mandatory safety, cases, evidence, restrictions, suspensions, retention, and Tool Logs.
                </p>
                <div class="shared-form-actions">
                  <button class="btn btn-danger" id="lobby-admin-moderation-trust-confirm" type="button">Confirm Disable</button>
                  <button class="btn" id="lobby-admin-moderation-trust-cancel" type="button">Cancel</button>
                </div>
              </section>
              <div class="settings-registry-sticky-actions">
                <span id="lobby-admin-settings-dirty-summary">No unsaved changes</span>
                <button class="btn btn-primary" id="lobby-admin-settings-save" type="submit" disabled>Save Changes</button>
                <div class="admin-form-status" aria-live="polite"></div>
              </div>
            </form>
          </div>
        </section>

        <section class="admin-section" id="admin-section-gestures">
          <div class="admin-section-title">Gestures</div>
          <div class="admin-section-sub">Manage shared gesture controls, safe metadata, validated packages, media, and provenance for Server Gestures.</div>
          <div class="admin-panel admin-gesture-settings-link">
            <div>
              <h3>Part 3 and Part 4 capability controls</h3>
              <p class="minor" id="admin-gesture-feature-summary">Loading shared settings…</p>
            </div>
            <button class="btn" id="admin-gesture-open-settings" type="button">Open shared gesture settings</button>
          </div>
          <div class="admin-panel admin-gesture-catalog-panel">
            <div class="admin-gesture-catalog-toolbar" role="search">
              <label>Search Server Gestures<input id="admin-gesture-search" type="search" maxlength="120" autocomplete="off"></label>
              <label>Sort<select id="admin-gesture-sort"><option value="last_uploaded">Last uploaded</option><option value="file_name">File name A–Z</option></select></label>
            </div>
            <div class="admin-gesture-catalog" id="admin-gesture-catalog" role="table" aria-label="Server Gesture metadata and package catalog"></div>
            <div class="gesture-pager" id="admin-gesture-pager" aria-label="Admin gesture catalog pages"></div>
            <div class="minor" id="admin-gesture-status" role="status" aria-live="polite"></div>
          </div>
        </section>

        <section class="admin-section" id="admin-section-database">
          <div class="admin-section-title">Database</div>
          <div class="admin-section-sub">Prepare protected application/database recovery, download SQLite backups, or move selected data through a portable JSON bundle.</div>
          <div class="admin-panel">
            <div class="admin-actions">
              <a class="btn btn-primary" href="<?= e(app_url('/database-update.php?owner=1')) ?>">Update &amp; Recovery</a>
              <a class="btn btn-primary" href="<?= e(app_url('/api/admin_database.php?action=download')) ?>">Full Backup</a>
              <form id="admin-db-export" class="admin-export-options">
                <?= csrf_input() ?>
                <div class="admin-import-note">
                  Select the portable data map to export. Files used by selected records are included in the JSON bundle.
                </div>
                <label class="admin-export-choice"><input name="users" type="checkbox" value="1" checked><span id="admin-user-export-label">User Data</span></label>
                <label class="admin-export-choice admin-export-subchoice"><input name="gestures" type="checkbox" value="1"><span>Include Gestures</span></label>
                <label class="admin-export-choice"><input name="rooms" type="checkbox" value="1" checked><span>Room Data</span></label>
                <label class="admin-export-choice"><input name="settings" type="checkbox" value="1" checked><span>Settings</span></label>
                <button class="btn btn-primary" type="submit">Export Selected</button>
              </form>
              <form id="admin-db-restore" class="admin-restore">
                <?= csrf_input() ?>
                <div class="admin-import-note">
                  Imports auto-detect full SQLite backups or portable JSON bundles. Portable imports apply whichever sections are present and match users by email.
                </div>
                <label class="file-picker">
                  <input name="database" type="file" accept=".sqlite,.db,.json,application/json,application/vnd.sqlite3,application/octet-stream" required>
                  <span class="file-picker-btn">Choose File</span>
                  <span class="file-picker-name" id="admin-db-restore-name">No file selected</span>
                </label>
                <span class="upload-progress" id="admin-db-import-progress" aria-live="polite">
                  <span class="upload-progress-track"><span class="upload-progress-bar"></span></span>
                  <span class="upload-progress-meta"><span class="upload-progress-msg">Waiting...</span><span class="upload-progress-pct">0%</span></span>
                </span>
                <button class="btn btn-danger" type="submit">Import</button>
              </form>
            </div>
          </div>
        </section>

        <section class="admin-section" id="admin-section-link-icons">
          <div class="admin-section-title">Link Pairing Icons</div>
          <div class="admin-section-sub">Add custom pairing icons for linked users. Built-in icons are protected, custom icons can be renamed or removed.</div>
          <div class="admin-panel">
            <form class="admin-link-icon-create" id="admin-link-icon-create" enctype="multipart/form-data">
              <?= csrf_input() ?>
              <input name="label" placeholder="Icon label" required>
              <label class="file-picker">
                <input name="icon" type="file" accept="image/png,image/webp,image/gif,image/jpeg" required>
                <span class="file-picker-btn">Choose Icon</span>
                <span class="file-picker-name" id="admin-link-icon-file-name">No file selected</span>
              </label>
              <button class="btn btn-primary" type="submit">Add Icon</button>
              <div class="admin-form-status" aria-live="polite"></div>
            </form>
          </div>
          <div class="admin-panel">
            <div class="admin-link-icons admin-scroll-list" id="admin-link-icons"></div>
          </div>
        </section>

        <section class="admin-section" id="admin-section-moderation">
          <div class="admin-section-title">Moderation</div>
          <div class="admin-section-sub">Remove blocks, undo room kicks, and reverse community ejections.</div>
          <div class="admin-moderation-grid">
            <section class="admin-panel">
              <h3>User Blocks</h3>
              <div class="admin-list" id="admin-blocks">Loading...</div>
            </section>
            <section class="admin-panel">
              <h3>Room Kicks</h3>
              <div class="admin-list" id="admin-room-ejections">Loading...</div>
            </section>
            <section class="admin-panel admin-panel-wide">
              <h3>Community Ejections</h3>
              <div class="admin-list" id="admin-community-ejections">Loading...</div>
            </section>
          </div>
        </section>

        <section class="admin-section" id="admin-section-logs">
          <div class="admin-section-title">Tool Logs</div>
          <div class="admin-section-sub">Review host, staff, and admin actions across rooms.</div>
          <div class="admin-panel">
            <div class="admin-list admin-log-list" id="admin-tool-logs">Loading...</div>
          </div>
        </section>

        <section class="admin-section" id="admin-section-errors">
          <div class="admin-section-title">Errors & Diagnostics</div>
          <div class="admin-section-sub">Review bounded runtime issues, resolution history, and locally censored diagnostic evidence.</div>
          <div class="admin-panel issue-workspace">
            <aside>
              <label>Status <select id="issue-status-filter"><option value="">All</option><option value="new">New</option><option value="confirmed">Confirmed</option><option value="investigating">Investigating</option><option value="fixed-pending-verification">Fixed pending verification</option><option value="resolved">Resolved</option><option value="expected">Expected</option><option value="ignored">Ignored</option><option value="regressed">Regressed</option></select></label>
              <div id="issue-list" class="issue-list"></div>
            </aside>
            <article id="issue-detail" class="issue-detail"><p class="minor">Select an issue.</p></article>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<script src="<?= e(app_url('/assets/js/settings-registry.js')) ?>"></script>
<script src="<?= e(app_url('/assets/js/lobby.js')) ?>"></script>
</body>
</html>
