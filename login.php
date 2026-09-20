<?php
require_once __DIR__ . '/includes/base.php';
$error = '';
$notice = ($_GET['account'] ?? '') === 'deleted'
    ? 'Your account has been deleted. Required shared history now appears under Deleted User.'
    : '';
$pdo = db();
if (!moderation_identity_policy_acceptance_storage_ready($pdo)) {
    redirect_to('/database-update.php');
}
$branding = private_site_branding_projection($pdo, 'login');
$brandingUtilityLinks = private_site_branding_utility_links($pdo);
security_protect_private_response();
$setupTwoFactor = ($_GET['setup2fa'] ?? '') === '1';
if (!empty($_SESSION['_two_factor_login']) && (int)$_SESSION['_two_factor_login']['expires'] < time()) {
    unset($_SESSION['_two_factor_login']); $error = 'Sign-in confirmation expired. Enter your password again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_two_factor') {
    unset($_SESSION['_two_factor_login']);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_two_factor') {
    try {
        $pending = $_SESSION['_two_factor_login'] ?? [];
        if (!$pending || (int)$pending['expires'] < time()) throw new TwoFactorException('Sign-in confirmation expired. Enter your password again.', 403);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([(int)$pending['userId']]);
        $candidate = $stmt->fetch(); $stmt->closeCursor();
        if (!$candidate || account_deletion_is_deleted($pdo, (int)$candidate['id'])
            || !hash_equals($pending['passwordHash'], hash('sha256', $candidate['password_hash']))
            || (int)(two_factor_row($pdo, (int)$candidate['id'])['revision'] ?? -1) !== (int)$pending['revision']) {
            unset($_SESSION['_two_factor_login']); throw new TwoFactorException('Account security changed. Enter your password again.', 403);
        }
        network_moderation_assert_request_allowed($pdo, (int)$candidate['id'], network_privacy_client_ip());
        two_factor_verify($pdo, (int)$candidate['id'], (string)($_POST['code'] ?? ''));
        unset($_SESSION['_two_factor_login']);
        authenticate_user((int)$candidate['id']);
        redirect_to(!empty($pending['setup']) ? '/account.php?tab=security&setup2fa=1' : '/lobby.php');
    } catch (TwoFactorException|SecurityPolicyViolation|NetworkPrivacyException $failure) { $error = $failure->getMessage(); }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $limit = auth_rate_limit_status($pdo, 'login', $login);
    if (!$limit['allowed']) {
        $error = $limit['message'];
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) OR LOWER(display_name) = LOWER(?) LIMIT 1');
        $stmt->execute([$login, $login, $login]);
        $user = $stmt->fetch();
        $stmt->closeCursor();
        if ($user && account_deletion_is_deleted($pdo, (int)$user['id'])) $user = false;
        if ($user && password_verify($password, $user['password_hash'])) {
            try {
                if (function_exists('network_moderation_observe_request')
                    && database_migration_table_exists($pdo, 'network_manual_bans')) {
                    network_moderation_observe_request(
                        $pdo,
                        (int)$user['id'],
                        'authentication',
                        'authentication:account:' . (int)$user['id'] . ':day:' . gmdate('Y-m-d'),
                        'Authentication for selected account #' . (int)$user['id']
                    );
                    network_moderation_assert_request_allowed(
                        $pdo,
                        (int)$user['id'],
                        network_privacy_client_ip()
                    );
                }
                auth_rate_clear_identifier($pdo, 'login', $login);
                unset($_SESSION['_two_factor_verified'], $_SESSION['_two_factor_login'], $_SESSION['_two_factor_enrollment']);
                if (two_factor_enabled($pdo, (int)$user['id'])) {
                    unset($_SESSION['user_id']); session_regenerate_id(true);
                    $_SESSION['_two_factor_login'] = ['userId' => (int)$user['id'], 'expires' => time() + 300,
                        'passwordHash' => hash('sha256', $user['password_hash']),
                        'revision' => (int)two_factor_row($pdo, (int)$user['id'])['revision'], 'setup' => $setupTwoFactor];
                    redirect_to('/login.php' . ($setupTwoFactor ? '?setup2fa=1' : ''));
                }
                authenticate_user((int)$user['id']);
                redirect_to($setupTwoFactor ? '/account.php?tab=security&setup2fa=1' : '/lobby.php');
            } catch (NetworkPrivacyException) {
                $error = 'Access from this network is restricted by an Installation Owner moderation action.';
            }
        }
        if ($error === '') {
            auth_rate_record_failure($pdo, 'login', $login);
            $afterFailure = auth_rate_limit_status($pdo, 'login', $login);
            $error = !$afterFailure['allowed'] ? $afterFailure['message'] : 'Login or password was not right.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(branded_page_title('Login', $pdo, 'login')) ?></title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
</head>
<body data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>">
<main class="auth-shell">
  <section class="auth-card">
    <a class="auth-logo-link" href="<?= e(app_url('/about.html')) ?>" aria-label="About ChatSpace Community Edition">
      <img class="auth-logo-full <?= $branding['has_custom_logo'] ? 'custom-brand-logo' : '' ?>" src="<?= e(app_url($branding['logo_path'])) ?>" alt="<?= e($branding['effective_name']) ?>">
    </a>
    <?php if ($notice): ?><div class="success" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['_two_factor_login'])): ?>
    <h1>Two-factor authentication</h1>
    <p>Enter the six-digit code from Aegis or your authenticator app, or one unused backup code.</p>
    <form class="form-grid" method="post">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="verify_two_factor">
      <label>Authenticator or backup code<input name="code" autocomplete="one-time-code" maxlength="32" required autofocus spellcheck="false" autocapitalize="characters"></label>
      <button class="btn btn-primary" type="submit">Verify and sign in</button>
    </form>
    <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="cancel_two_factor"><button class="btn" type="submit">Use a different account</button></form>
    <?php else: ?>
    <?php if ($setupTwoFactor): ?><p>Sign in first, then set up optional two-factor authentication.</p><?php endif; ?>
    <form class="form-grid" method="post">
      <?= csrf_input() ?>
      <label>Email or username<input name="login" required autocomplete="username"></label>
      <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
      <button class="btn btn-primary" type="submit">Log In</button>
      <div class="auth-action-panel">
        <span>New here?</span>
        <a class="btn btn-primary auth-main-link" href="<?= e(app_url('/register.php')) ?>">Create an Account</a>
      </div>
      <div class="auth-utility-actions">
        <a class="auth-utility-btn" href="<?= e(app_url('/recover.php')) ?>">Recover Account</a>
        <a class="auth-utility-btn" href="<?= e(app_url('/login.php?setup2fa=1')) ?>">Set up 2FA</a>
        <a class="auth-utility-btn auth-about-btn" href="<?= e(app_url('/about.html')) ?>">About ChatSpace CE</a>
        <?php foreach ($brandingUtilityLinks as $utilityLink): ?>
          <a class="auth-utility-btn auth-changelog-btn" href="<?= e(app_url((string)$utilityLink['path'])) ?>"><?= e((string)$utilityLink['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </form>
    <?php endif; ?>
    <?php if ($branding['has_custom_logo'] && ($branding['show_powered_logo'] ?? true)): ?>
      <div class="powered-by auth-powered-by">
        <span>Powered by</span>
        <img src="<?= e(app_url($branding['powered_logo_path'])) ?>" alt="ChatSpace Community Edition">
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
