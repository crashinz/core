<?php
require_once __DIR__ . '/includes/base.php';

$pdo = db();
$branding = private_site_branding_projection($pdo, 'login');
$content = '';
$available = true;
$document = trim((string)($_GET['document'] ?? 'changelog'));
$documents = [
    'changelog' => [
        'path' => 'MODIFICATIONS.md',
        'title' => "exe's Changelog",
        'kicker' => 'Public modification history',
        'description' => 'Rendered directly from the canonical MODIFICATIONS.md source.',
    ],
    'third-party-notices' => [
        'path' => 'THIRD_PARTY_NOTICES.md',
        'title' => 'Third-Party Notices',
        'kicker' => 'Public source and license notices',
        'description' => 'Rendered directly from the canonical THIRD_PARTY_NOTICES.md source.',
    ],
];
if (!isset($documents[$document])) $document = 'changelog';
$selectedDocument = $documents[$document];
$sourcePath = __DIR__ . '/' . $selectedDocument['path'];
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
  <title><?= e(private_site_branding_page_title($pdo, $selectedDocument['title'], 'login')) ?></title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
</head>
<body data-app-base="<?= e(app_base_path()) ?>">
<main class="public-document-shell">
  <header class="public-document-header">
    <a class="auth-logo-link" href="<?= e(app_url('/about.html')) ?>" aria-label="About ChatSpace Community Edition">
      <img class="auth-logo-full <?= $branding['has_custom_logo'] ? 'custom-brand-logo' : '' ?>" src="<?= e(app_url($branding['logo_path'])) ?>" alt="<?= e($branding['effective_name']) ?>">
    </a>
    <div>
      <p class="public-document-kicker"><?= e($selectedDocument['kicker']) ?></p>
      <h1><?= e($selectedDocument['title']) ?></h1>
      <p><?= e($selectedDocument['description']) ?></p>
    </div>
    <nav class="public-document-actions" aria-label="Changelog navigation">
      <a class="btn btn-primary" href="<?= e(app_url('/login.php')) ?>">Return to Login</a>
      <a class="btn" href="<?= e(app_url('/about.html')) ?>">About ChatSpace CE</a>
      <a class="btn" href="<?= e(app_url('/license.php')) ?>">View original License</a>
      <a class="btn<?= $document === 'changelog' ? ' btn-primary' : '' ?>" href="<?= e(app_url('/changelog.php')) ?>">exe's Changelog</a>
      <a class="btn<?= $document === 'third-party-notices' ? ' btn-primary' : '' ?>" href="<?= e(app_url('/changelog.php?document=third-party-notices')) ?>">Third-Party Notices</a>
    </nav>
  </header>
  <article class="public-document-content changelog-content">
    <?php if ($available): ?>
      <?= $content ?>
    <?php else: ?>
      <h2>Document unavailable</h2>
      <p>The selected canonical public document is missing, unreadable, or exceeds the bounded document limit.</p>
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
