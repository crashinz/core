<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/base.php';

$viewer = require_user();
$pdo = db();
$branding = private_site_branding_projection($pdo, 'other');
$assetVersion = static fn(string $path): string => app_url($path) . '?v='
    . rawurlencode((string)(is_file(__DIR__ . $path) ? filemtime(__DIR__ . $path) : time()));

try {
    $targetUserId = member_profiles_user_id_for_public_profile_id(
        $pdo,
        $_GET['id'] ?? ''
    );
    $profile = member_profiles_projection(
        $pdo,
        (int)$viewer['id'],
        $targetUserId
    );
} catch (MemberProfileException $error) {
    http_response_code($error->httpStatus === 404 ? 404 : 400);
    $profile = null;
    $profileError = $error->getMessage();
}

$displayName = is_array($profile)
    ? (string)($profile['effectiveDisplayName'] ?? $profile['displayName'] ?? 'Member')
    : 'Member Profile';
$fields = is_array($profile) ? [
    'Display name' => $profile['displayName'] ?? null,
    'Name' => $profile['name'] ?? null,
    'In a relationship with' => $profile['relationshipWith'] ?? null,
    'Location' => $profile['location'] ?? null,
    'About Me' => $profile['aboutMe'] ?? null,
    'Public profile contact email' => $profile['publicContactEmail'] ?? null,
    'Website' => $profile['website'] ?? null,
    'Interests' => $profile['interests'] ?? null,
    'Discord username' => $profile['discordUsername'] ?? null,
    'Registered' => $profile['registeredAt'] ?? null,
] : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(branded_page_title($displayName, $pdo, 'other')) ?></title>
  <link rel="stylesheet" href="<?= e($assetVersion('/assets/css/styles.css')) ?>">
</head>
<body class="shared-surface-body">
<main class="shared-surface">
  <header class="shared-surface-head">
    <div>
      <div class="side-title"><?= e($branding['effective_name'] === 'ChatSpace Community Edition' ? 'ChatSpace' : $branding['effective_name']) ?></div>
      <h1>Member Profile</h1>
    </div>
    <a class="btn" href="<?= e(app_url('/lobby.php')) ?>">Back to Lobby</a>
  </header>
  <?php if (!is_array($profile)): ?>
    <div class="shared-status error" role="alert"><?= e($profileError ?? 'That member profile is unavailable.') ?></div>
  <?php else: ?>
    <article class="member-profile-page" aria-labelledby="member-profile-page-title">
      <section class="member-profile-identity" aria-label="Member identity">
        <div id="member-profile-avatar">
          <?php if (!empty($profile['avatarUrl'])): ?>
            <img class="member-profile-avatar-image" src="<?= e((string)$profile['avatarUrl']) ?>" alt="<?= e($displayName . ' avatar') ?>">
          <?php else: ?>
            <div class="member-profile-avatar-placeholder" role="img" aria-label="<?= e((string)($profile['avatarHiddenNotice'] ?? 'Standard member avatar')) ?>">
              <?= e((string)($profile['avatarHiddenNotice'] ?? 'Standard avatar')) ?>
            </div>
          <?php endif; ?>
        </div>
        <div>
          <h2 id="member-profile-page-title"><?= e($displayName) ?></h2>
        </div>
      </section>
      <dl class="member-profile-fields">
        <?php foreach ($fields as $label => $value): ?>
          <?php if ($value === null || trim((string)$value) === '') continue; ?>
          <dt><?= e($label) ?></dt>
          <dd class="<?= in_array($label, ['About Me', 'Interests'], true) ? 'member-profile-multiline' : '' ?>">
            <?php if ($label === 'Website'): ?>
              <a href="<?= e((string)$value) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)$value) ?></a>
            <?php elseif ($label === 'Public profile contact email'): ?>
              <a href="mailto:<?= e((string)$value) ?>"><?= e((string)$value) ?></a>
            <?php else: ?>
              <?= e((string)$value) ?>
            <?php endif; ?>
          </dd>
        <?php endforeach; ?>
      </dl>
      <section class="member-profile-history" aria-labelledby="member-profile-page-history">
        <h3 id="member-profile-page-history">Previous display names</h3>
        <ul>
          <?php foreach ((array)($profile['previousDisplayNames'] ?? []) as $entry): ?>
            <li><span><?= e((string)($entry['displayName'] ?? '')) ?></span> <small><?= e((string)($entry['changedAt'] ?? '')) ?></small></li>
          <?php endforeach; ?>
          <?php if (empty($profile['previousDisplayNames'])): ?><li class="minor">None</li><?php endif; ?>
        </ul>
      </section>
      <?php if (!empty($profile['priorUsernameUseWarning'])): ?>
        <div class="member-profile-warning" role="note"><?= e((string)$profile['priorUsernameUseWarning']) ?></div>
      <?php endif; ?>
      <?php if (!empty($profile['isSelf'])): ?>
        <div class="member-profile-actions">
          <a class="btn btn-primary" href="<?= e(app_url('/account.php')) ?>">Edit Public Profile</a>
        </div>
      <?php endif; ?>
    </article>
  <?php endif; ?>
</main>
</body>
</html>
