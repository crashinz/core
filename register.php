<?php
require_once __DIR__ . '/includes/base.php';
$error = '';
$pdo = db();
$branding = private_site_branding_projection($pdo, 'registration');
$registrationPolicy = moderation_identity_registration_policy($pdo);
$policyBundle = moderation_identity_current_policy_bundle();
$ageGateEnabled = app_setting($pdo, 'age_gate_enabled', '0') === '1';
$ageGateMinAge = max(1, min(120, (int)app_setting($pdo, 'age_gate_min_age', '13')));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_FILES['avatar']['tmp_name'])) {
        security_authorize_outside_content_or_json($pdo, null, 'registration_avatar', ['source' => 'registration']);
    }
    $username = trim((string)($_POST['username'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $name = trim($_POST['display_name'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $avatarPath = 'preset:Default';
    if (!empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['avatar']['tmp_name']) ?: '';
        $allowed = ['image/gif' => 'gif', 'image/webp' => 'webp'];
        $dims = @getimagesize($_FILES['avatar']['tmp_name']);
        $validDims = security_valid_image_file((string)$_FILES['avatar']['tmp_name'], $mime)
            && $dims[0] >= 42 && $dims[1] >= 42 && $dims[0] <= 250 && $dims[1] <= 250;
        if (isset($allowed[$mime]) && (int)$_FILES['avatar']['size'] <= 5 * 1024 * 1024 && $validDims) {
            $file = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
            $dest = __DIR__ . '/assets/uploads/avatars/' . $file;
            move_uploaded_file($_FILES['avatar']['tmp_name'], $dest);
            $avatarPath = '/assets/uploads/avatars/' . $file;
            security_assert_storage_destination('registration_avatar', $avatarPath);
        }
    }
    $ageVerified = !$ageGateEnabled || !empty($_POST['age_gate_confirm']);
    if ($ageGateEnabled && !$ageVerified) {
        $error = 'You must verify that you are at least ' . $ageGateMinAge . ' to create an account.';
    } elseif ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $error = 'Use a valid Username, email, and password of at least 8 characters.';
    } else {
        try {
            $transaction = database_transaction_begin($pdo, !db_uses_mysql_syntax($pdo));
            $result = moderation_identity_register_account($pdo, [
                'username' => $username,
                'email' => $email,
                'display_name' => $name,
                'password' => $password,
                'avatar_path' => $avatarPath,
                'invitation_token' => (string)($_POST['invitation_token'] ?? ''),
                'accept_terms' => !empty($_POST['accept_terms']),
                'accept_rules' => !empty($_POST['accept_rules']),
            ], 'self-registration');
            $userId = (int)$result['userId'];
            database_transaction_commit($pdo, $transaction);
            authenticate_user($userId);
            redirect_to('/lobby.php');
        } catch (Throwable $e) {
            if (isset($transaction) && is_array($transaction)) database_transaction_rollback($pdo, $transaction);
            if ($avatarPath !== 'preset:Default') {
                $uploaded = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $avatarPath);
                if (is_file($uploaded)) @unlink($uploaded);
            }
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(branded_page_title('Sign Up', $pdo, 'registration')) ?></title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
</head>
<body data-app-base="<?= e(app_base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>">
<main class="auth-shell">
  <section class="auth-card">
    <a class="auth-logo-link" href="<?= e(app_url('/about.html')) ?>" aria-label="About ChatSpace Community Edition">
      <img class="auth-logo-full <?= $branding['has_custom_logo'] ? 'custom-brand-logo' : '' ?>" src="<?= e(app_url($branding['logo_path'])) ?>" alt="<?= e($branding['effective_name']) ?>">
    </a>
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <form class="form-grid" method="post" enctype="multipart/form-data">
      <?= csrf_input() ?>
      <label>Email<input type="email" name="email" required autocomplete="email"></label>
      <label>Username<input name="username" required minlength="3" maxlength="32" pattern="[a-z0-9][a-z0-9_.\x2d]{2,31}" autocomplete="username"></label>
      <label>Display name <span class="minor">(optional; Username is shown when blank)</span><input name="display_name" autocomplete="nickname"></label>
      <label>Avatar <span class="minor">(optional; a safe built-in avatar is used when blank)</span><input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp"></label>
      <label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <?php if ($registrationPolicy['invitationRequired']): ?>
      <label>Invitation code<input name="invitation_token" required autocomplete="one-time-code"></label>
      <?php endif; ?>
      <div class="policy-acceptance-summary">
        <p>Review the complete <a href="<?= e(app_url('/policy.php')) ?>">Terms of Use v<?= e($policyBundle['terms']['version']) ?> and Community Rules v<?= e($policyBundle['communityRules']['version']) ?></a>.</p>
        <label class="check-label"><input type="checkbox" name="accept_terms" value="1" required> I accept the complete current Terms of Use.</label>
        <label class="check-label"><input type="checkbox" name="accept_rules" value="1" required> I accept the complete current Community Rules.</label>
      </div>
      <?php if ($ageGateEnabled): ?>
      <label class="check-label"><input type="checkbox" name="age_gate_confirm" value="1" required> I confirm that I am at least <?= e((string)$ageGateMinAge) ?>.</label>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit" <?= $registrationPolicy['administratorCreatedOnly'] ? 'disabled' : '' ?>>Sign Up</button>
      <?php if ($registrationPolicy['administratorCreatedOnly']): ?><p class="minor" role="status">Accounts are created by an Administrator for this community.</p><?php endif; ?>
      <div class="auth-action-panel">
        <span>Already have an account?</span>
        <a class="btn btn-primary auth-main-link" href="<?= e(app_url('/login.php')) ?>">Log In</a>
      </div>
      <div class="auth-utility-actions single">
        <a class="auth-utility-btn auth-about-btn" href="<?= e(app_url('/about.html')) ?>">About ChatSpace CE</a>
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
<script src="<?= e(app_url('/assets/js/avatar-processing.js')) ?>"></script>
<script src="<?= e(app_url('/assets/js/register.js')) ?>"></script>
</body>
</html>
