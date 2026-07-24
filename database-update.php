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
            $result = database_recovery_run_update(
                $pdo,
                (int)$actor['id'],
                (string)($_POST['request_public_id'] ?? '')
            );
            $notice = !empty($result['no_op'])
                ? 'The database was already current. No migration ran.'
                : 'The protected recovery set and database update completed successfully.';
        } elseif ($action === 'prepare') {
            $actor = database_update_require_admin($pdo);
            if ((string)($_POST['prepare_confirm'] ?? '') !== 'prepare-paired-set') {
                throw new CoreMigrationException(
                    'Prepare for Update requires deliberate confirmation.',
                    'RECOVERY_PREPARE_CONFIRMATION_REQUIRED',
                    400
                );
            }
            $prepared = database_recovery_prepare(
                $pdo,
                (int)$actor['id'],
                (string)($_POST['request_public_id'] ?? '')
            );
            $notice = 'The paired database and application recovery set is verified. It is safe to overwrite the deployable application files.';
        } elseif ($action === 'verify_recovery') {
            $actor = database_update_require_admin($pdo);
            database_recovery_verify_set(
                $pdo,
                (string)($_POST['recovery_set_id'] ?? '')
            );
            $notice = 'The selected paired recovery set remains complete, private, readable, and verified.';
        } elseif ($action === 'exit_prepared') {
            $actor = database_update_require_admin($pdo);
            database_recovery_exit_prepared(
                $pdo,
                (int)$actor['id'],
                (string)($_POST['recovery_set_id'] ?? '')
            );
            $notice = 'Prepared maintenance was exited safely. The verified recovery set remains preserved.';
        } elseif ($action === 'reconcile_recovery') {
            $actor = database_update_require_admin($pdo);
            database_recovery_reconcile_interrupted(
                $pdo,
                (int)$actor['id'],
                (int)($_POST['expected_recovery_revision'] ?? -1)
            );
            $notice = 'The interrupted recovery phase was reconciled from durable state.';
        } elseif ($action === 'restore_pair') {
            $actor = database_update_require_admin($pdo);
            if ((string)($_POST['restore_confirm'] ?? '') !== 'restore-paired-set') {
                throw new CoreMigrationException(
                    'Paired restoration requires deliberate confirmation.',
                    'RECOVERY_RESTORE_CONFIRMATION_REQUIRED',
                    400
                );
            }
            database_recovery_restore_pair(
                $pdo,
                (int)$actor['id'],
                (string)($_POST['recovery_set_id'] ?? ''),
                (string)($_POST['request_public_id'] ?? '')
            );
            $notice = 'The verified paired recovery set was restored and compatibility checks passed.';
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
$recoveryStatus = database_recovery_status($pdo);
$isAdmin = $actor && ($actor['role'] ?? '') === 'admin';
$recent = $isAdmin && security_recent_authentication_valid();
$ownerEntryRequested = isset($_GET['owner']) || $requestAction === 'authenticate';
$maintenanceRequired = !$status['current'] || !empty($recoveryStatus['maintenance']);
$publicMaintenance = !$isAdmin && $maintenanceRequired && !$ownerEntryRequested;
$ownerAuthentication = !$isAdmin && $ownerEntryRequested;
$title = $maintenanceRequired ? 'CoreChat Update & Recovery' : 'Database Current';
$updateRequestPublicId = uuid_v4();
$prepareRequestPublicId = uuid_v4();
$restoreRequestPublicId = uuid_v4();
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
$recoverySet = is_array($recoveryStatus['recovery_set'] ?? null)
    ? $recoveryStatus['recovery_set']
    : null;
$preparedMaintenance = !empty($recoveryStatus['maintenance'])
    && ($recoveryStatus['phase'] ?? '') === 'prepared';
$recoveryRequired = !empty($recoveryStatus['maintenance'])
    && in_array(
        (string)($recoveryStatus['phase'] ?? ''),
        ['prepare-failed', 'recovery-required', 'restore-failed'],
        true
    );
$installedRelease = is_array($recoveryStatus['installed_release'] ?? null)
    ? $recoveryStatus['installed_release']
    : null;
$recoveryPairUnavailable = !empty($recoveryStatus['maintenance'])
    && !empty($recoveryStatus['active_recovery_set_id'])
    && $recoverySet === null;
$incompletePrepare = $recoveryPairUnavailable
    && in_array(
        (string)$recoveryStatus['phase'],
        ['prepare-started', 'database-recovery-point-verified', 'application-snapshot-verified', 'prepare-failed'],
        true
    );
$interruptedRecovery = !empty($recoveryStatus['maintenance'])
    && $recoverySet !== null
    && in_array(
        (string)$recoveryStatus['phase'],
        ['restore-started', 'application-restored-database-pending', 'migration-started', 'migration-preflight-complete', 'post-update-validation'],
        true
    );
$automaticUpdateAllowed = $automaticUpdateAllowed
    && $installedRelease !== null
    && !$recoveryPairUnavailable;
$applicationFilesChangedAfterPrepare = $preparedMaintenance
    && $recoverySet !== null
    && $installedRelease !== null
    && !hash_equals(
        (string)$recoverySet['source_release_id'],
        (string)$installedRelease['release_id']
    );
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
    <?php elseif ($status['current'] && !$isAdmin && !$ownerEntryRequested && empty($recoveryStatus['maintenance'])): ?>
      <p>The configured <?= e(strtoupper((string)$status['engine'])) ?> database is compatible with this CoreChat release.</p>
      <a class="btn btn-primary" href="<?= e(app_url('/login.php')) ?>">Continue to CoreChat</a>
    <?php else: ?>
      <p>
        <?= $maintenanceRequired
          ? 'Protected update or recovery maintenance is active. Normal CoreChat routes remain unavailable until application and database compatibility is verified.'
          : 'The owner-only Update & Recovery workspace can prepare a private paired recovery set before application files are overwritten.' ?>
      </p>
      <dl class="settings-summary">
        <dt>Application release</dt><dd><?= e((string)($installedRelease['release_id'] ?? 'Unverified')) ?></dd>
        <dt>Application inventory</dt><dd><?= $installedRelease === null
          ? e((string)($recoveryStatus['installed_release_error_code'] ?? 'Unavailable'))
          : (int)$installedRelease['file_count'] . ' verified files' ?></dd>
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
        <dt>Recovery phase</dt><dd><?= e((string)$recoveryStatus['phase']) ?></dd>
        <dt>Paired recovery set</dt><dd><?= $recoverySet === null
          ? e((string)($recoveryStatus['recovery_set_error_code'] ?? 'Not prepared'))
          : e((string)$recoverySet['recovery_set_id']) ?></dd>
        <?php if ($recoverySet !== null): ?>
          <dt>Recovery-set timestamp</dt><dd><?= e((string)$recoverySet['created_at']) ?></dd>
          <dt>Database recovery point</dt><dd><?= e((string)$recoverySet['database_recovery_point_id']) ?> · <?= (int)$recoverySet['database_byte_size'] ?> bytes · checksum verified</dd>
          <dt>Application snapshot</dt><dd><?= (int)$recoverySet['application_file_count'] ?> files · <?= (int)$recoverySet['application_byte_size'] ?> bytes · inventory verified</dd>
          <dt>Automatic restore</dt><dd><?= !empty($recoveryStatus['automatic_restore']['supported'])
            ? 'Supported for this engine'
            : (($recoveryStatus['automatic_restore']['reason'] ?? '') === 'action-time-privilege-preflight-required'
                ? 'MariaDB capability is verified immediately before restore'
                : 'Manual database restore boundary applies') ?></dd>
        <?php endif; ?>
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
        <?php if (!$maintenanceRequired): ?>
          <div class="settings-warning">
            Prepare for Update is optional. It enters maintenance and creates one verified private recovery set pairing
            a transaction-consistent database recovery point with an inventory-driven snapshot of this installed
            deployable application release. Configuration, databases, uploads, private storage, and other
            installation-specific content are excluded from the application snapshot.
          </div>
          <form class="form-grid" method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="prepare">
            <input type="hidden" name="request_public_id" value="<?= e($prepareRequestPublicId) ?>">
            <label>
              <input type="checkbox" name="prepare_confirm" value="prepare-paired-set" required>
              Enter maintenance and create the verified paired recovery set.
            </label>
            <button
              class="btn btn-primary"
              type="submit"
              <?= $installedRelease === null || empty($status['backup_readiness']['ok']) ? 'disabled' : '' ?>
            >Prepare for Update</button>
          </form>
        <?php elseif ($status['kind'] === 'active'): ?>
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
        <?php elseif ($incompletePrepare): ?>
          <div class="error" role="alert">
            Prepare for Update stopped before one complete paired recovery set was verified. Application overwrite was
            not authorized. Reconcile the exact durable phase, or exit maintenance only if the installed application
            and database still match the recorded pre-update identities.
          </div>
          <form class="form-grid" method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="reconcile_recovery">
            <input type="hidden" name="expected_recovery_revision" value="<?= (int)$recoveryStatus['revision'] ?>">
            <button class="btn btn-primary" type="submit">Resume Verified Recovery</button>
          </form>
          <?php if ($recoveryStatus['phase'] === 'prepare-failed'): ?>
            <form class="form-grid" method="post">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="exit_prepared">
              <input type="hidden" name="recovery_set_id" value="<?= e((string)$recoveryStatus['active_recovery_set_id']) ?>">
              <button class="btn" type="submit">Exit Prepared Maintenance</button>
            </form>
          <?php endif; ?>
        <?php elseif ($recoveryPairUnavailable): ?>
          <div class="error" role="alert">
            The active paired recovery set is incomplete, altered, cross-installation, corrupt, or unavailable.
            No update or restore mutation is allowed. Error code:
            <code><?= e((string)($recoveryStatus['recovery_set_error_code'] ?? 'RECOVERY_SET_UNAVAILABLE')) ?></code>.
            Restore the exact private recovery-set components from protected storage or follow the bounded hosting-owner recovery record.
          </div>
        <?php elseif ($interruptedRecovery): ?>
          <div class="settings-warning">
            The prior protected operation stopped between durable phases. Reconciliation first proves no recovery owner
            is active and rechecks the exact state revision; it never steals a live lock or blindly repeats mutation.
          </div>
          <form class="form-grid" method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="reconcile_recovery">
            <input type="hidden" name="expected_recovery_revision" value="<?= (int)$recoveryStatus['revision'] ?>">
            <button class="btn btn-primary" type="submit">Resume Verified Recovery</button>
          </form>
        <?php elseif ($recoveryRequired && $recoverySet !== null): ?>
          <div class="settings-warning">
            The update cannot resume safely. Restore uses this exact verified recovery set and keeps maintenance active
            until the matching application snapshot and database recovery point are jointly compatible. MariaDB
            restoration fails closed to bounded hosting-owner instructions when the deployment account is not certified
            for an automatic server-side transition.
          </div>
          <form class="form-grid" method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="verify_recovery">
            <input type="hidden" name="recovery_set_id" value="<?= e((string)$recoverySet['recovery_set_id']) ?>">
            <button class="btn" type="submit">Verify Again</button>
          </form>
          <form class="form-grid" method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="restore_pair">
            <input type="hidden" name="recovery_set_id" value="<?= e((string)$recoverySet['recovery_set_id']) ?>">
            <input type="hidden" name="request_public_id" value="<?= e($restoreRequestPublicId) ?>">
            <label>
              <input type="checkbox" name="restore_confirm" value="restore-paired-set" required>
              Restore both verified recovery-set components and remain in maintenance until validation passes.
            </label>
            <button class="btn btn-danger" type="submit">Restore Verified Recovery Set</button>
          </form>
        <?php else: ?>
          <div class="settings-warning">
            <?= $preparedMaintenance
              ? ($applicationFilesChangedAfterPrepare
                  ? 'The deployable application release changed after preparation. This action revalidates the paired recovery set, applies any pending migration exactly once, and releases maintenance only after the uploaded release and database are compatible.'
                  : 'The paired recovery set is prepared. Overwrite only the deployable application files, then reopen this page. You may exit prepared maintenance safely while the application and database remain unchanged.')
              : 'This action first creates and independently verifies a private database backup, then applies every pending core migration in order. MariaDB backups stream directly into private server-side storage; the browser never downloads or re-uploads them.' ?>
          </div>
          <?php if (!$preparedMaintenance || $applicationFilesChangedAfterPrepare): ?>
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
          <?php if ($preparedMaintenance && $recoverySet !== null): ?>
            <form class="form-grid" method="post">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="verify_recovery">
              <input type="hidden" name="recovery_set_id" value="<?= e((string)$recoverySet['recovery_set_id']) ?>">
              <button class="btn" type="submit">Verify Again</button>
            </form>
            <?php if (!$applicationFilesChangedAfterPrepare): ?>
              <form class="form-grid" method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="exit_prepared">
                <input type="hidden" name="recovery_set_id" value="<?= e((string)$recoverySet['recovery_set_id']) ?>">
                <button class="btn" type="submit">Exit Prepared Maintenance</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
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
