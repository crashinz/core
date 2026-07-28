<?php
require_once __DIR__ . '/includes/base.php';

$pdo = db();
$branding = private_site_branding_projection($pdo, 'login');
$content = '';
$available = true;
$sourcePath = __DIR__ . '/MODIFICATIONS.md';
$sourceSize = is_file($sourcePath) ? filesize($sourcePath) : false;
if ($sourceSize !== false && $sourceSize <= 262144) {
    $markdown = file_get_contents($sourcePath);
}
if (isset($markdown) && $markdown !== false) {
    $content = private_site_branding_render_modifications($markdown);
} else {
    http_response_code(404);
    $available = false;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(private_site_branding_page_title($pdo, "exe's Changelog", 'login')) ?></title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
</head>
<body data-app-base="<?= e(app_base_path()) ?>">
<main class="public-document-shell">
  <header class="public-document-header">
    <a class="auth-logo-link" href="<?= e(app_url('/about.html')) ?>" aria-label="About ChatSpace Community Edition">
      <img class="auth-logo-full <?= $branding['has_custom_logo'] ? 'custom-brand-logo' : '' ?>" src="<?= e(app_url($branding['logo_path'])) ?>" alt="<?= e($branding['effective_name']) ?>">
    </a>
    <div>
      <p class="public-document-kicker">Public modification history</p>
      <h1>exe's Changelog</h1>
      <p>Rendered directly from the canonical <code>MODIFICATIONS.md</code> source.</p>
    </div>
    <nav class="public-document-actions" aria-label="Changelog navigation">
      <a class="btn btn-primary" href="<?= e(app_url('/login.php')) ?>">Return to Login</a>
      <a class="btn" href="<?= e(app_url('/about.html')) ?>">About ChatSpace CE</a>
      <a class="btn" href="<?= e(app_url('/license.php')) ?>">View original License</a>
    </nav>
  </header>
  <article class="public-document-content changelog-content">
    <?php if ($available): ?>
      <?= $content ?>
    <?php else: ?>
      <h2>Changelog unavailable</h2>
      <p>The canonical modification-history source is missing, unreadable, or exceeds the bounded document limit.</p>
    <?php endif; ?>
  </article>
  <footer class="public-document-footer">
    <strong>ChatSpace Community Edition</strong>
    <span><?= e(chatspace_application_version()) ?></span>
    <span>Modified by exe</span>
  </footer>
</main>
</body>
</html>
