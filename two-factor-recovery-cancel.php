<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/base.php';
security_protect_private_response();
header('Referrer-Policy: no-referrer');
$error = ''; $done = false;
$id = (int)($_GET['account'] ?? 0); $token = (string)($_GET['token'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!account_email_ready(db())) throw new TwoFactorException('Recovery is unavailable.',503);
        account_email_cancel(db(),$id,$token); $done = true;
    } catch (TwoFactorException $e) { $error = $e->getMessage(); }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cancel authenticator recovery</title>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>"></head>
<body><main class="auth-shell"><section class="auth-card"><h1>Cancel authenticator recovery</h1>
<?php if ($done): ?><p role="status">Recovery cancelled. Your authenticator and backup codes remain active.</p>
<?php else: ?>
<p>This stops the requested email recovery. It does not turn off two-factor authentication.</p>
<?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post"><?= csrf_input() ?><button type="submit" class="btn btn-primary">Cancel recovery</button></form>
<?php endif; ?>
<a class="btn" href="<?= e(app_url('/login.php')) ?>">Return to sign in</a>
</section></main></body></html>
