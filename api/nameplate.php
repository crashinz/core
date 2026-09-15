<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/upload_duplicates.php';

require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/nameplate_policy.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$pdo = db();
$sessionId = resolve_session_id($pdo, $_POST['session_id'] ?? '');
$participant = auth_participant($pdo, $sessionId, $_POST['join_token'] ?? '');
$action = trim((string)($_POST['action'] ?? 'upload'));

if ($action === 'remove') {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET nameplate_path = NULL WHERE id = ?')
            ->execute([(int)$participant['user_id']]);
        $pdo->prepare('UPDATE participants SET nameplate_path = NULL WHERE user_id = ?')
            ->execute([(int)$participant['user_id']]);
        nameplate_visibility_source_changed($pdo, (int)$participant['user_id']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    emit_event($pdo, $sessionId, 'nameplate', [
        'participant_id' => (int)$participant['id'],
        'nameplate_path' => null,
        'nameplate_url' => null,
    ]);
    json_out(['ok' => true, 'nameplate_path' => null, 'nameplate_url' => null]);
}

$selectedAsset = null;
if ($action === 'select') {
    csrf_protect_post();
    security_authorize_outside_content_or_json($pdo, ['id' => (int)$participant['user_id']], 'avatar_upload', ['session_id' => $sessionId]);
    try {
        $selectedAsset = server_media_select_library_asset($pdo, (int)$participant['user_id'], 'nameplate', (string)($_POST['library_id'] ?? ''));
    } catch (ServerMediaException $error) {
        json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
    }
    $public = (string)$selectedAsset['source_key'];
    $destination = (string)$selectedAsset['storage_path'];
    $mime = $selectedAsset['selection_mime'];
} else {
    if ($action !== 'upload') json_out(['error' => 'Unsupported nameplate action'], 400);
    if (empty($_FILES['nameplate']['tmp_name'])
    || (int)($_FILES['nameplate']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_uploaded_file($_FILES['nameplate']['tmp_name'])) {
    json_out(['error' => 'Nameplate image required'], 400);
    }

    security_authorize_outside_content_or_json(
    $pdo,
    ['id' => (int)$participant['user_id']],
    'avatar_upload',
    ['session_id' => $sessionId]
    );

    $temporary = (string)$_FILES['nameplate']['tmp_name'];
    $inspected = nameplate_upload_inspect($pdo, $temporary);
    if (isset($inspected['error'])) json_out(['error' => $inspected['error']], 400);
    $mime = $inspected['mime'];
    $uploadTransaction = database_transaction_begin($pdo, true);
    upload_duplicate_lock($pdo, 'nameplate', (int)$participant['user_id']);
    $duplicate = upload_duplicate_find_image($pdo, (int)$participant['user_id'], 'nameplate', $temporary);
    if ($duplicate !== null) {
        database_transaction_commit($pdo, $uploadTransaction);
        json_out($duplicate);
    }
    $file = 'nameplate-' . bin2hex(random_bytes(12)) . '.' . $inspected['extension'];
    $public = '/assets/uploads/nameplates/' . $file;
    security_assert_storage_destination('nameplate_upload', $public);
    $directory = __DIR__ . '/../assets/uploads/nameplates';
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
    json_out(['error' => 'Nameplate image could not be stored. Try again.'], 500);
    }
    $destination = $directory . '/' . $file;
    if (!move_uploaded_file($temporary, $destination)) {
    json_out(['error' => 'Nameplate image could not be stored. Try again.'], 500);
    }

}

try {
    $uploadTransaction ??= database_transaction_begin($pdo, true);
    $pdo->prepare('UPDATE users SET nameplate_path = ? WHERE id = ?')
        ->execute([$public, (int)$participant['user_id']]);
    $pdo->prepare('UPDATE participants SET nameplate_path = ? WHERE user_id = ?')
        ->execute([$public, (int)$participant['user_id']]);
    nameplate_visibility_source_changed($pdo, (int)$participant['user_id']);
    $nameplateLibraryId = $selectedAsset['public_id'] ?? null;
    if ($selectedAsset === null) {
        $nameplateLibraryId = server_media_register_nameplate(
            $pdo,
            (int)$participant['user_id'],
            $public,
            $destination,
            $mime
        );
        set_app_setting($pdo, 'nameplate_library.name.' . $nameplateLibraryId, mb_substr(basename(str_replace('\\', '/', (string)($_FILES['nameplate']['name'] ?? 'Nameplate'))), 0, 180));
    }
    database_transaction_commit($pdo, $uploadTransaction);
} catch (Throwable $error) {
    database_transaction_rollback($pdo, $uploadTransaction);
    if ($selectedAsset === null) @unlink($destination);
    throw $error;
}

$url = resolve_nameplate($public);
emit_event($pdo, $sessionId, 'nameplate', [
    'participant_id' => (int)$participant['id'],
    'nameplate_path' => $public,
    'nameplate_url' => $url,
]);
json_out(['ok' => true, 'nameplate_path' => $public, 'nameplate_url' => $url, 'library_id' => $nameplateLibraryId]);
