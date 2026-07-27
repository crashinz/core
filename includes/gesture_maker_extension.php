<?php
declare(strict_types=1);

const GESTURE_MAKER_EXTENSION_ID = 'gesture-maker';

function gesture_maker_extension_adapter(): array {
    return [
        'id' => GESTURE_MAKER_EXTENSION_ID,
        'renderEditor' => 'gesture_maker_extension_render_editor',
    ];
}

/**
 * Render only the presentation owned by the Gesture Maker extension.
 *
 * The host supplies a bounded, already-authorized view model. All gesture
 * identity, authorization, persistence, package validation, protected
 * delivery, concurrency, and logging remain behind mandatory-core endpoints.
 */
function gesture_maker_extension_render_editor(array $view): void {
    $publicId = (string)($view['publicId'] ?? '');
    $admin = !empty($view['admin']);
    $editorApi = (string)($view['editorApi'] ?? '');
    if ($editorApi !== '/api/gesture_packages.php') {
        throw new RuntimeException('Gesture Maker core service binding is invalid.');
    }
    $title = $publicId === ''
        ? 'Create Gesture'
        : ($admin ? 'Manage Server Gesture' : 'Edit Gesture');
    $mode = $publicId === ''
        ? 'Create a private Personal Gesture'
        : ($admin ? 'Admin package and metadata management' : 'Edit your Personal Gesture');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title) ?> - ChatSpace CE</title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('/extensions/gesture-maker/assets/gesture-editor.css')) ?>">
</head>
<body class="gesture-editor-body"
      data-app-base="<?= e(app_base_path()) ?>"
      data-csrf="<?= e(csrf_token()) ?>"
      data-gesture-id="<?= e($publicId) ?>"
      data-admin="<?= $admin ? 'true' : 'false' ?>"
      data-gesture-editor-api="<?= e($editorApi) ?>">
<main class="gesture-editor-shell" aria-labelledby="gesture-editor-heading">
  <header class="gesture-editor-header">
    <div>
      <h1 id="gesture-editor-heading"><?= e($title) ?></h1>
      <p id="gesture-editor-mode"><?= e($mode) ?></p>
    </div>
    <button class="gesture-editor-close" id="gesture-editor-close" type="button" aria-label="Close Gesture Maker">×</button>
  </header>
  <form id="gesture-editor-form" novalidate>
    <div class="gesture-editor-grid">
      <section class="gesture-editor-panel" aria-label="Gesture fields and source media">
        <div class="gesture-editor-fields">
          <label class="gesture-editor-field"><span>Gesture title</span><input id="gesture-title" name="title" maxlength="120" required autocomplete="off"><small>Shown in your gesture catalog.</small></label>
          <label class="gesture-editor-field"><span>Catalog filename</span><input id="gesture-catalog-filename" name="catalog_filename" maxlength="120" required autocomplete="off"><small>A safe display name only; it never controls physical storage.</small></label>
          <label class="gesture-editor-field gesture-editor-field-wide"><span>Canonical Gesture text</span><textarea id="gesture-text" name="text" maxlength="180" required></textarea><small>Messages use the exact format (Gesture) Gesture Text.</small></label>
          <label class="gesture-editor-field"><span>Creator credit</span><input id="gesture-creator-credit" name="creator_credit" maxlength="120" required autocomplete="off"><small>Human-readable credit; it is not ownership authority.</small></label>
          <div class="gesture-editor-field" id="gesture-uploaded-by-row" hidden><span>Uploaded by</span><output id="gesture-uploaded-by"></output><small>Server-authored and read-only.</small></div>
          <div class="gesture-editor-field"><span>Current revision</span><output id="gesture-current-version">New</output><small>Stale saves fail without partial replacement.</small></div>
        </div>
        <div class="gesture-editor-media">
          <label id="gesture-package-input-row"><strong>Import AGST package</strong><input id="gesture-package" type="file" accept=".agst,application/zip"><small>Canonical v1 and source-backed legacy toc/meta packages are validated on Save.</small></label>
          <label id="gesture-animation-input-row"><strong>Animation GIF</strong><input id="gesture-animation" type="file" accept="image/gif,.gif"><small>Required for Create unless an AGST package is selected.</small></label>
          <label><strong>Static poster</strong><input id="gesture-poster" type="file" accept="image/gif,image/png,image/jpeg,image/webp"><small>Optional safe fallback/inspection image.</small></label>
          <label id="gesture-audio-input-row"><strong>Sound MP3</strong><input id="gesture-audio" type="file" accept="audio/mpeg,.mp3"><small>Optional; sound never autoplays in this editor.</small></label>
        </div>
        <div class="gesture-editor-removals">
          <label id="remove-poster-row" hidden><input id="gesture-remove-poster" type="checkbox"> Remove current poster on Save</label>
          <label id="remove-audio-row" hidden><input id="gesture-remove-audio" type="checkbox"> Remove current sound on Save</label>
        </div>
      </section>
      <section class="gesture-editor-panel" aria-label="Gesture preview and package status">
        <div class="gesture-editor-preview" id="gesture-editor-preview" aria-live="polite"><span>Loading preview…</span></div>
        <div class="gesture-editor-preview-text" id="gesture-editor-preview-text"></div>
        <div class="gesture-editor-preview-explanation" id="gesture-editor-preview-explanation"></div>
        <button class="btn" id="gesture-editor-audio-preview" type="button" hidden>Play sound preview</button>
        <div class="gesture-editor-package-summary" id="gesture-editor-package-summary" aria-label="Package summary"></div>
        <p class="gesture-editor-attribution">Gesture Maker and editing code created by Catie, creator of ChatSpace Community Edition. Original Gesture Maker code ownership remains with Catie.</p>
      </section>
    </div>
    <div class="gesture-editor-validation" id="gesture-editor-validation" tabindex="-1" role="alert" hidden></div>
    <div class="gesture-editor-actions">
      <button class="primary" id="gesture-editor-save" type="submit">Save Gesture</button>
      <button id="gesture-download-package" type="button" hidden>Download Gesture Package</button>
      <button id="gesture-editor-cancel" type="button">Cancel</button>
      <span class="gesture-editor-status" id="gesture-editor-status" role="status" aria-live="polite"></span>
    </div>
  </form>
</main>
<script type="module" src="<?= e(app_url('/extensions/gesture-maker/assets/gesture-editor.js')) ?>"></script>
</body>
</html>
    <?php
}
