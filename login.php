<?php
require_once __DIR__ . '/includes/base.php';
$error = '';
$pdo = db();
$branding = private_site_branding_projection($pdo, 'login');
$brandingUtilityLinks = private_site_branding_utility_links($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $limit = auth_rate_limit_status($pdo, 'login', $login);
    if (!$limit['allowed']) {
        $error = $limit['message'];
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) OR LOWER(display_name) = LOWER(?) LIMIT 1');
        $stmt->execute([$login, $login, $login]);
        $user = $stmt->fetch();
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
                authenticate_user((int)$user['id']);
                redirect_to('/lobby.php');
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
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
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
        <a class="auth-utility-btn auth-about-btn" href="<?= e(app_url('/about.html')) ?>">About ChatSpace CE</a>
        <?php foreach ($brandingUtilityLinks as $utilityLink): ?>
          <a class="auth-utility-btn auth-changelog-btn" href="<?= e(app_url((string)$utilityLink['path'])) ?>"><?= e((string)$utilityLink['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </form>
    <?php if ($branding['has_custom_logo']): ?>
      <div class="powered-by auth-powered-by">
        <span>Powered by</span>
        <img src="<?= e(app_url($branding['powered_logo_path'])) ?>" alt="ChatSpace Community Edition">
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
