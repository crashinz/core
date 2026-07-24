<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/base.php';

$pdo = db_migration_connection();
$error = '';
$notice = '';

function database_update_actor(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id']) || !database_migration_table_exists($pdo, 'users')) return null;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function database_update_require_admin(PDO $pdo): array
{
    $actor = database_update_actor($pdo);
    if (!$actor || ($actor['role'] ?? '') !== 'admin') {
        throw new CoreMigrationException('Administrator authentication is required.', 'MIGRATION_ADMIN_REQUIRED', 403);
    }
    security_require_recent_authentication();
    return $actor;
}

$actor = database_update_actor($pdo);
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestAction = (string)($_POST['action'] ?? '');

if ($requestMethod === 'POST') {
    $action = $requestAction;
    try {
        if ($action === 'authenticate') {
            $login = trim((string)($_POST['login'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $limit = auth_rate_limit_status($pdo, 'database-update-login', $login);
            if (!$limit['allowed']) throw new CoreMigrationException($limit['message'], 'MIGRATION_LOGIN_RATE_LIMITED', 429);
            $stmt = $pdo->prepare(
                "SELECT * FROM users WHERE role = 'admin' AND (LOWER(email) = LOWER(?) OR LOWER(display_name) = LOWER(?)) LIMIT 1"
            );
            $stmt->execute([$login, $login]);
            $candidate = $stmt->fetch();
            if (!$candidate || !password_verify($password, (string)$candidate['password_hash'])) {
                auth_rate_record_failure($pdo, 'database-update-login', $login);
                throw new CoreMigrationException('Administrator sign-in was not accepted.', 'MIGRATION_LOGIN_FAILED', 403);
            }
            auth_rate_clear_identifier($pdo, 'database-update-login', $login);
            authenticate_user((int)$candidate['id']);
            $actor = $candidate;
            $notice = 'Administrator authentication confirmed. Review the update status before continuing.';
        } elseif ($action === 'update') {
            $actor = database_update_require_admin($pdo);
            $result = database_migrations_run(
                $pdo,
                (int)$actor['id'],
                false,
                (string)($_POST['request_public_id'] ?? '')
            );
            $notice = !empty($result['no_op'])
                ? 'The database was already current. No migration ran.'
                : 'The database backup and update completed successfully.';
        } elseif ($action === 'recover') {
            $actor = database_update_require_admin($pdo);
            database_migration_prepare_interrupted_recovery(
                $pdo,
                (string)($_POST['attempt_public_id'] ?? ''),
                (int)$actor['id']
            );
            $notice = 'The interrupted owner was proven absent. The verified recovery state is ready to resume.';
        } elseif ($action === 'signout') {
            security_destroy_session();
            $actor = null;
            $notice = 'The database-update session was signed out.';
        } else {
            throw new CoreMigrationException('Unknown database-update action.', 'MIGRATION_ACTION_UNKNOWN', 400);
        }
    } catch (Throwable $caught) {
        $error = $caught->getMessage();
        http_response_code($caught instanceof CoreMigrationException ? $caught->httpStatus : 500);
    }
}

$status = database_migration_status($pdo);
$isAdmin = $actor && ($actor['role'] ?? '') === 'admin';
$recent = $isAdmin && security_recent_authentication_valid();
$ownerEntryRequested = isset($_GET['owner']) || $requestAction === 'authenticate';
$publicMaintenance = !$isAdmin && !$status['current'] && !$ownerEntryRequested;
$ownerAuthentication = !$isAdmin && !$status['current'] && $ownerEntryRequested;
$title = $status['current'] ? 'Database Current' : 'CoreChat Database Update Required';
$updateRequestPublicId = uuid_v4();
$backupMethod = (string)($status['backup_readiness']['method'] ?? '');
$backupMethodLabel = match ($backupMethod) {
    CORE_MIGRATION_MARIADB_BACKUP_FORMAT => 'Private server-side logical stream',
    'sqlite-vacuum-into' => 'Private consistent SQLite snapshot',
    'recovery-backup-revalidation' => 'Previously verified private backup',
    'not-required' => 'Not required',
    default => $backupMethod !== '' ? $backupMethod : 'Unavailable',
};
$blockedUpdateKinds = ['newer', 'unknown', 'incomplete-release', 'inconsistent'];
$updateStateBlocked = in_array((string)$status['kind'], $blockedUpdateKinds, true);
$automaticUpdateAllowed = $status['release_complete']
    && !empty($status['backup_readiness']['ok'])
    && !$updateStateBlocked;
$hasVerifiedRecoveryBackup = is_array($status['migration_state']['backup'] ?? null);
$updateActionLabel = $status['kind'] === 'failed'
    ? ($hasVerifiedRecoveryBackup
        ? 'Resume Verified Database Recovery'
        : 'Back Up and Retry Database Update')
    : 'Back Up and Update Database';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
</head>
<body data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>">
<main class="auth-shell">
  <section class="auth-card database-update-card">
    <h1><?= e($title) ?></h1>
    <?php if ($notice): ?><div class="success" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <?php if ($publicMaintenance): ?>
      <p>CoreChat is temporarily unavailable while the site owner completes a required database update.</p>
      <p>No chat or account data has been changed by this page. Please try again after the owner finishes the update.</p>
      <a class="btn" href="<?= e(app_url('/database-update.php?owner=1')) ?>">Site Owner Database Update</a>
    <?php elseif ($ownerAuthentication): ?>
      <p>Administrator authentication is required before database details or update controls are shown.</p>
      <form class="form-grid" method="post" autocomplete="on">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="authenticate">
        <label>Administrator email or username<input name="login" required autocomplete="username"></label>
        <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" type="submit">Confirm Administrator</button>
      </form>
    <?php elseif ($status['current']): ?>
      <p>The configured <?= e(strtoupper((string)$status['engine'])) ?> database is compatible with this CoreChat release.</p>
      <a class="btn btn-primary" href="<?= e(app_url('/login.php')) ?>">Continue to CoreChat</a>
    <?php else: ?>
      <p>A protected, forward-only database update is required before normal CoreChat routes can run.</p>
      <dl class="settings-summary">
        <dt>Database engine</dt><dd><?= e(strtoupper((string)$status['engine'])) ?></dd>
        <dt>Detected version</dt><dd><?= e((string)($status['stored_schema_version'] ?: 'Legacy unversioned database')) ?></dd>
        <dt>Required version</dt><dd><?= e((string)$status['required_schema_version']) ?></dd>
        <dt>Detected variant</dt><dd><?= e((string)$status['variant']['id']) ?></dd>
        <dt>Pending migrations</dt><dd><?= (int)$status['pending_count'] ?></dd>
        <dt>Migration package</dt><dd><?= $status['release_complete'] ? 'Complete' : 'Blocked' ?></dd>
        <dt>Exclusive lock</dt><dd><?= e((string)$status['lock_status']) ?></dd>
        <dt>Backup readiness</dt><dd><?= !empty($status['backup_readiness']['ok']) ? 'Ready' : 'Blocked' ?></dd>
        <dt>Backup transport</dt><dd><?= e($backupMethodLabel) ?></dd>
        <dt>Attempt state</dt><dd><?= e((string)($status['migration_state']['phase'] ?? 'No prior attempt')) ?></dd>
      </dl>

      <?php if ($status['pending']): ?>
        <h2>Ordered update</h2>
        <ol>
          <?php foreach ($status['pending'] as $migration): ?>
            <li><code><?= e((string)$migration['id']) ?></code> — <?= e((string)$migration['title']) ?></li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <?php if ($status['defects']): ?>
        <div class="error" role="alert">
          <strong>Update preflight is blocked.</strong>
          <ul>
            <?php foreach ($status['defects'] as $defect): ?><li><?= e((string)$defect) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($updateStateBlocked): ?>
        <div class="error" role="alert">
          <?= $status['kind'] === 'newer'
            ? 'This database is newer than these application files. Automatic downgrade is prohibited; install compatible application files.'
            : 'This database state is not recognized as a safe forward migration source. No automatic mutation is allowed.' ?>
        </div>
      <?php endif; ?>

      <?php if ($status['kind'] === 'failed'): ?>
        <div class="error" role="alert">
          The prior migration failed safely and remains in protected recovery state.
          Error code: <code><?= e((string)($status['migration_state']['error_code'] ?? 'MIGRATION_FAILED')) ?></code>.
          <?= $hasVerifiedRecoveryBackup
            ? 'Its private backup will be revalidated before any continuation.'
            : 'No verified backup is attached; a new private backup is required before any retry.' ?>
        </div>
      <?php endif; ?>

      <?php if (empty($status['backup_readiness']['ok'])): ?>
        <div class="error" role="alert"><?= e((string)($status['backup_readiness']['message'] ?? 'Private backup storage is not ready.')) ?></div>
      <?php endif; ?>

      <?php if (!$recent): ?>
        <h2>Administrator confirmation</h2>
        <form class="form-grid" method="post" autocomplete="on">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="authenticate">
          <label>Administrator email or username<input name="login" required autocomplete="username"></label>
          <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
          <button class="btn btn-primary" type="submit">Confirm Administrator</button>
        </form>
      <?php else: ?>
        <?php if ($status['kind'] === 'active'): ?>
          <div class="settings-warning">
            The prior migration attempt still has durable active state. Recovery never breaks ownership because of
            elapsed time alone. This action succeeds only if both the process lock and database lock prove that the
            recorded owner is absent and the exact attempt identity still matches.
          </div>
          <form class="form-grid" method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="recover">
            <input type="hidden" name="attempt_public_id" value="<?= e((string)($status['migration_state']['attempt_public_id'] ?? '')) ?>">
            <button class="btn btn-primary" type="submit">Verify Interrupted Attempt for Recovery</button>
          </form>
        <?php else: ?>
          <div class="settings-warning">
            This action first creates and independently verifies a private database backup, then applies every pending
            core migration in order. A previously verified interrupted-attempt backup is revalidated and reused.
            MariaDB backups stream directly into private server-side storage; the browser never downloads or re-uploads them.
            Do not close the update page or replace application files while it is running.
          </div>
          <form class="form-grid" method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="request_public_id" value="<?= e($updateRequestPublicId) ?>">
            <button
              class="btn btn-primary"
              type="submit"
              <?= !$automaticUpdateAllowed ? 'disabled' : '' ?>
            ><?= e($updateActionLabel) ?></button>
          </form>
        <?php endif; ?>
        <form method="post">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="signout">
          <button class="btn" type="submit">Sign Out</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
