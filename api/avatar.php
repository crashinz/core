<?php
require_once __DIR__ . '/../includes/base.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$pdo = db();
$sessionId = resolve_session_id($pdo, $_POST['session_id'] ?? '');
$p = auth_participant($pdo, $sessionId, $_POST['join_token'] ?? '');
$action = trim((string)($_POST['action'] ?? 'upload'));

if ($action === 'set_orientation') {
    $result = avatar_orientation_update(
        $pdo,
        (int)$p['user_id'],
        $_POST['expected_orientation_version'] ?? null,
        $_POST['avatar_orientation'] ?? null,
        $_POST['expected_orientation'] ?? null
    );
    if (empty($result['ok'])) {
        $status = (int)($result['http_status'] ?? 400);
        unset($result['http_status']);
        json_out($result, $status);
    }
    $orientation = avatar_orientation_normalize($result['avatar_orientation'] ?? null);
    emit_event($pdo, $sessionId, 'avatar', array_merge([
        'participant_id' => (int)$p['id'],
        'avatar_path' => (string)$result['avatar_path'],
        'avatar_url' => resolve_avatar((string)$result['avatar_path']),
        'avatar_orientation' => $orientation,
        'avatar_orientation_version' => (int)$result['avatar_orientation_version'],
        'webcam_path' => $p['webcam_path'] ?? null,
        'webcam_enabled' => !empty($p['webcam_enabled']),
    ], avatar_size_participant_event_fields($pdo, $p)));
    json_out([
        'ok' => true,
        'idempotent' => !empty($result['idempotent']),
        'avatar_orientation' => $orientation,
        'avatar_orientation_version' => (int)$result['avatar_orientation_version'],
    ]);
}

if ($action === 'set_display_preferences') {
    $changes = [];
    foreach (['avatar_display_size_px', 'webcam_display_width_px', 'webcam_display_height_px'] as $field) {
        if (array_key_exists($field, $_POST)) $changes[$field] = $_POST[$field];
    }
    $result = avatar_size_preferences_update(
        $pdo,
        (int)$p['user_id'],
        $_POST['expected_size_version'] ?? null,
        $changes
    );
    if (empty($result['ok'])) {
        $status = (int)($result['http_status'] ?? 400);
        unset($result['http_status']);
        json_out($result, $status);
    }
    $participantStmt = $pdo->prepare('SELECT * FROM participants WHERE id = ? LIMIT 1');
    $participantStmt->execute([(int)$p['id']]);
    $updatedParticipant = $participantStmt->fetch() ?: $p;
    emit_event($pdo, $sessionId, 'avatar', array_merge([
        'participant_id' => (int)$p['id'],
        'avatar_path' => (string)$updatedParticipant['avatar_path'],
        'avatar_url' => resolve_avatar((string)$updatedParticipant['avatar_path']),
        'avatar_orientation' => avatar_orientation_normalize($updatedParticipant['avatar_orientation'] ?? null),
        'avatar_orientation_version' => max(1, (int)($updatedParticipant['avatar_orientation_version'] ?? 1)),
        'webcam_path' => $updatedParticipant['webcam_path'] ?? null,
        'webcam_enabled' => !empty($updatedParticipant['webcam_enabled']),
    ], avatar_size_participant_event_fields($pdo, $updatedParticipant)));
    json_out([
        'ok' => true,
        'idempotent' => !empty($result['idempotent']),
        'preferences' => $result['preferences'],
        'avatarSizePolicy' => avatar_size_policy($pdo),
    ]);
}

if (empty($_FILES['avatar']['tmp_name'])
    || (int)($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_uploaded_file($_FILES['avatar']['tmp_name'])) {
    json_out(['error' => 'Avatar image required'], 400);
}
security_authorize_outside_content_or_json($pdo, ['id' => (int)$p['user_id']], 'avatar_upload', ['session_id' => $sessionId]);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['avatar']['tmp_name']) ?: '';
$allowed = ['image/gif' => 'gif', 'image/webp' => 'webp'];
$allowedImageTypes = ['image/gif' => IMAGETYPE_GIF, 'image/webp' => IMAGETYPE_WEBP];
$maxBytes = app_setting_bytes($pdo, 'avatar_max_size_mb', 5);
$sizePolicy = avatar_size_policy($pdo);
$dims = @getimagesize($_FILES['avatar']['tmp_name']);
$validDecodedType = $dims
    && isset($allowedImageTypes[$mime])
    && (int)($dims[2] ?? 0) === $allowedImageTypes[$mime];
if (!isset($allowed[$mime]) || !$validDecodedType) {
    json_out(['error' => 'Use a valid GIF or WebP avatar image.'], 400);
}
if ((int)$_FILES['avatar']['size'] > $maxBytes) {
    json_out(['error' => 'Avatar images must be under ' . app_setting($pdo, 'avatar_max_size_mb', '5') . ' MB.'], 400);
}
if ((int)$dims[0] < AVATAR_UPLOAD_MIN_DIMENSION_PX || (int)$dims[1] < AVATAR_UPLOAD_MIN_DIMENSION_PX
    || (int)$dims[0] > (int)$sizePolicy['avatarUploadMaxWidthPx']
    || (int)$dims[1] > (int)$sizePolicy['avatarUploadMaxHeightPx']) {
    json_out([
        'error' => 'Avatar images must be at least ' . AVATAR_UPLOAD_MIN_DIMENSION_PX . 'x'
            . AVATAR_UPLOAD_MIN_DIMENSION_PX . ' and no larger than '
            . (int)$sizePolicy['avatarUploadMaxWidthPx'] . 'x'
            . (int)$sizePolicy['avatarUploadMaxHeightPx'] . ' pixels.',
    ], 400);
}

$file = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
$dest = __DIR__ . '/../assets/uploads/avatars/' . $file;
if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
    json_out(['error' => 'Avatar image could not be stored. Try again.'], 500);
}
$public = '/assets/uploads/avatars/' . $file;
security_assert_storage_destination('avatar_upload', $public);
$avatarIdentity = avatar_identity_for_source($public, $dest);
$avatarWidth = max(1, (int)$dims[0]);
$avatarHeight = max(1, (int)$dims[1]);

try {
    $pdo->beginTransaction();
    avatar_identity_apply(
        $pdo,
        (int)$p['user_id'],
        $public,
        $avatarIdentity,
        $avatarWidth,
        $avatarHeight
    );
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink($dest);
    throw $error;
}

$participantStmt = $pdo->prepare('SELECT * FROM participants WHERE id = ? LIMIT 1');
$participantStmt->execute([(int)$p['id']]);
$updatedParticipant = $participantStmt->fetch() ?: array_merge($p, [
    'avatar_path' => $public,
    'avatar_source_width_px' => $avatarWidth,
    'avatar_source_height_px' => $avatarHeight,
    'webcam_path' => null,
    'webcam_enabled' => 0,
]);

emit_event($pdo, $sessionId, 'avatar', array_merge([
    'participant_id' => (int)$p['id'],
    'avatar_path' => $public,
    'avatar_url' => $public,
    'avatar_source_width_px' => $avatarWidth,
    'avatar_source_height_px' => $avatarHeight,
    'avatar_orientation' => avatar_orientation_normalize($p['avatar_orientation'] ?? null),
    'avatar_orientation_version' => max(1, (int)($updatedParticipant['avatar_orientation_version'] ?? 1)),
    'webcam_path' => null,
    'webcam_enabled' => false,
], avatar_size_participant_event_fields($pdo, $updatedParticipant)));

json_out([
    'ok' => true,
    'avatar_path' => $public,
    'avatar_url' => $public,
    'avatar_source_width_px' => $avatarWidth,
    'avatar_source_height_px' => $avatarHeight,
    'avatar_orientation' => avatar_orientation_normalize($p['avatar_orientation'] ?? null),
    'avatar_orientation_version' => max(1, (int)($updatedParticipant['avatar_orientation_version'] ?? 1)),
    'preferences' => avatar_size_preferences_public($pdo, $updatedParticipant),
]);
