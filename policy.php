<?php
declare(strict_types=1);

define('CHATSPACE_POLICY_ACCEPTANCE_ROUTE', true);
require_once __DIR__ . '/includes/base.php';

$pdo = db();
if (!moderation_identity_policy_acceptance_storage_ready($pdo)) {
    redirect_to('/database-update.php');
}
$user = current_user();
$bundle = moderation_identity_current_policy_bundle();
$error = '';

function policy_inline_markdown(string $text): string
{
    $escaped = e($text);
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    return preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;
}

function policy_render_markdown(string $markdown): string
{
    $html = [];
    $paragraph = [];
    $listType = null;
    $listItems = [];
    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if (!$paragraph) return;
        $html[] = '<p>' . policy_inline_markdown(implode(' ', $paragraph)) . '</p>';
        $paragraph = [];
    };
    $flushList = static function () use (&$listType, &$listItems, &$html): void {
        if ($listType === null || !$listItems) return;
        $items = array_map(static fn(string $item): string => '<li>' . policy_inline_markdown($item) . '</li>', $listItems);
        $html[] = '<' . $listType . '>' . implode('', $items) . '</' . $listType . '>';
        $listType = null;
        $listItems = [];
    };

    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushParagraph();
            $flushList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $match) === 1) {
            $flushParagraph();
            $flushList();
            $level = min(6, strlen($match[1]) + 2);
            $html[] = '<h' . $level . '>' . policy_inline_markdown($match[2]) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $match) === 1) {
            $flushParagraph();
            if ($listType !== null && $listType !== 'ul') $flushList();
            $listType = 'ul';
            $listItems[] = $match[1];
            continue;
        }
        if (preg_match('/^\d+[.)]\s+(.+)$/', $trimmed, $match) === 1) {
            $flushParagraph();
            if ($listType !== null && $listType !== 'ol') $flushList();
            $listType = 'ol';
            $listItems[] = $match[1];
            continue;
        }
        $flushList();
        $paragraph[] = $trimmed;
    }
    $flushParagraph();
    $flushList();
    return implode("\n", $html);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) redirect_to('/login.php');
    if (empty($_POST['accept_terms']) || empty($_POST['accept_rules'])) {
        $error = 'Review and accept both complete documents to continue.';
    } else {
        moderation_identity_record_acceptance($pdo, (int)$user['id'], 'material-reacceptance');
        redirect_to('/lobby.php');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Terms and Community Rules</title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
</head>
<body data-app-base="<?= e(app_base_path()) ?>">
<main class="auth-shell">
  <section class="auth-card policy-review-card" aria-labelledby="policy-review-title">
    <h1 id="policy-review-title">Terms and Community Rules</h1>
    <p>Read the complete current documents. Material changes require full reacceptance before ordinary access.</p>
    <?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php foreach ([$bundle['terms'], $bundle['communityRules']] as $document): ?>
      <article class="policy-document" aria-labelledby="policy-<?= e($document['id']) ?>">
        <h2 id="policy-<?= e($document['id']) ?>"><?= e($document['title']) ?> <span class="minor">v<?= e($document['version']) ?></span></h2>
        <div class="policy-document-content"><?= policy_render_markdown((string)$document['content']) ?></div>
      </article>
    <?php endforeach; ?>
    <?php if ($user): ?>
      <form method="post" class="form-grid">
        <?= csrf_input() ?>
        <label class="check-label"><input type="checkbox" name="accept_terms" value="1" required> I accept the complete Terms of Use v<?= e(MODERATION_IDENTITY_TERMS_VERSION) ?>.</label>
        <label class="check-label"><input type="checkbox" name="accept_rules" value="1" required> I accept the complete Community Rules v<?= e(MODERATION_IDENTITY_RULES_VERSION) ?>.</label>
        <button class="btn btn-primary" type="submit">Accept and Continue</button>
      </form>
    <?php else: ?>
      <a class="btn btn-primary" href="<?= e(app_url('/register.php')) ?>">Return to Sign Up</a>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
