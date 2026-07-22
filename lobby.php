<?php
require_once __DIR__ . '/includes/room_importer.php';
$user = require_user();
$pdo = db();
$branding = install_branding($pdo);
$communityEjection = active_community_ejection($pdo, (int)$user['id']);
if ($communityEjection) {
    redirect_to('/community_ejected.php');
}
$ejectionNotice = $_SESSION['room_ejection_notice'] ?? null;
unset($_SESSION['room_ejection_notice']);
$lobbyError = null;
$canonicalAdminLaunch = (string)($_GET['admin'] ?? '') === '1';
$staffRoles = ['admin', 'developer'];
if ($canonicalAdminLaunch && !in_array($user['role'] ?? 'user', $staffRoles, true)) {
    http_response_code(403);
    exit('Administrator or developer access is required.');
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
  <title><?= e(branded_page_title('Lobby', $pdo)) ?></title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
</head>
<body data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>" data-is-admin="<?= ($user['role'] ?? '') === 'admin' ? 'true' : 'false' ?>" data-canonical-admin-launch="<?= $canonicalAdminLaunch ? 'true' : 'false' ?>" data-role-colors-mode="<?= e($roleColors['mode']) ?>" style="<?= e(role_color_css_variables($pdo)) ?>">
<main class="picker-shell">
  <section class="picker-main">
    <div class="topbar">
      <div class="lobby-brand-block">
        <div class="app-title">
          <img class="<?= $branding['has_custom_logo'] ? 'custom-brand-logo' : '' ?>" src="<?= e(app_url($branding['has_custom_logo'] ? $branding['logo_path'] : '/assets/images/chatspace-ce-logo.png')) ?>" alt="<?= e($branding['community_name'] ?: 'ChatSpace Community Edition') ?>">
          <div>
            <div class="app-name"><?= e($branding['community_name'] ?: 'ChatSpace') ?></div>
            <div class="app-edition"><?= $branding['community_name'] ? 'Community powered by ChatSpace CE' : 'Community Edition' ?></div>
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
          <div class="admin-panel">
            <form class="admin-create" id="admin-create">
              <?= csrf_input() ?>
              <input name="display_name" placeholder="Display name" required>
              <input name="email" type="email" placeholder="Email" required>
              <input name="password" type="password" placeholder="Password" required>
              <select name="role">
                <option value="user">User</option>
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
        </section>

        <section class="admin-section" id="admin-section-settings">
          <div class="admin-section-title">Settings</div>
          <div class="admin-section-sub">Search and manage installation policy through the shared Setup/Admin registry.</div>
          <div class="admin-panel settings-registry-shell">
            <div class="settings-registry-heading">
              <div><h3>Installation Settings</h3><p class="minor">Persistence remains with each authoritative policy owner.</p></div>
              <div class="settings-registry-state" id="lobby-admin-settings-compatibility-state" aria-live="polite">Loading settings…</div>
            </div>
            <div id="lobby-admin-settings-unlock"></div>
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
          <div class="admin-section-sub">Manage Part 3 presentation controls through the shared registry and inspect the read-only Server Gesture catalog.</div>
          <div class="admin-panel admin-gesture-settings-link">
            <div>
              <h3>Part 3 capability and presentation controls</h3>
              <p class="minor" id="admin-gesture-feature-summary">Loading shared settings…</p>
            </div>
            <button class="btn" id="admin-gesture-open-settings" type="button">Open shared gesture settings</button>
          </div>
          <div class="admin-panel admin-gesture-catalog-panel">
            <div class="admin-gesture-catalog-toolbar" role="search">
              <label>Search Server Gestures<input id="admin-gesture-search" type="search" maxlength="120" autocomplete="off"></label>
              <label>Sort<select id="admin-gesture-sort"><option value="last_uploaded">Last uploaded</option><option value="file_name">File name A–Z</option></select></label>
            </div>
            <div class="admin-gesture-catalog" id="admin-gesture-catalog" role="table" aria-label="Read-only Server Gesture catalog"></div>
            <div class="gesture-pager" id="admin-gesture-pager" aria-label="Admin gesture catalog pages"></div>
            <div class="minor" id="admin-gesture-status" role="status" aria-live="polite"></div>
          </div>
        </section>

        <section class="admin-section" id="admin-section-database">
          <div class="admin-section-title">Database</div>
          <div class="admin-section-sub">Download full SQLite backups, or move users, rooms, settings, and files through a portable JSON bundle.</div>
          <div class="admin-panel">
            <div class="admin-actions">
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
