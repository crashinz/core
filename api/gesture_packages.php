<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$actor = require_user();
$pdo = db();
$features = gesture_part4_feature_flags($pdo);
$capabilities = gesture_capability_policy($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? '';

function gesture_package_api_bool(mixed $value): bool
{
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function gesture_package_api_feature(array $features, string $key, string $message): void
{
    if (empty($features[$key])) throw new GestureCatalogException($message, 403, 'GESTURE_PART4_FEATURE_DISABLED');
}

function gesture_package_api_download(PDO $pdo, array $actor, string $publicId): never
{
    if (($actor['role'] ?? '') === 'admin') security_require_recent_authentication_or_json();
    $requestId = trim((string)($_GET['request_id'] ?? ''));
    [$record, $reservation, $requestId] = gesture_catalog_transaction(
        $pdo,
        function () use ($pdo, $actor, $publicId, $requestId): array {
            gesture_capability_lock($pdo);
            $record = gesture_package_download_record($pdo, $actor, $publicId);
            security_authorize_outside_content_or_json(
                $pdo,
                $actor,
                'gesture_package_download',
                ['source' => $record['admin'] ? 'admin' : 'gesture-catalog']
            );
            $reservation = null;
            if (!$record['owner'] && !$record['admin']) {
                if (!preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $requestId)) {
                    $requestId = 'gesture-download-' . bin2hex(random_bytes(12));
                }
                $reservation = gesture_catalog_begin_download(
                    $pdo,
                    (int)$actor['id'],
                    $publicId,
                    $requestId
                );
            }
            return [$record, $reservation, $requestId];
        }
    );
    $temporary = null;
    try {
        $path = gesture_package_download_path($record);
        if ($path === null) {
            $temporary = gesture_package_ephemeral_legacy_download($record);
            $path = $temporary['path'];
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > GESTURE_PACKAGE_MAX_COMPRESSED) throw new GestureCatalogException('Gesture package is unavailable.', 404, 'PACKAGE_UNAVAILABLE');
        $filename = gesture_catalog_filename_stem((string)($record['gesture']['catalog_filename'] ?: $record['gesture']['name']), 'gesture') . '.agst';
        security_protect_private_response();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        if ($reservation) gesture_catalog_finish_download($pdo, (int)$actor['id'], $requestId, 'completed', (int)$size);
        else log_tool($pdo, (int)$actor['id'], $record['admin'] ? 'gesture_part4_admin_package_download' : 'gesture_part4_owner_package_download', (int)$record['gesture']['owner_user_id'], null, json_encode(['gesture_public_id' => $publicId, 'bytes' => (int)$size], JSON_UNESCAPED_SLASHES));
        exit;
    } catch (Throwable $error) {
        if ($reservation) {
            try { gesture_catalog_finish_download($pdo, (int)$actor['id'], $requestId, 'failed', 0, $error instanceof GestureCatalogException ? $error->errorCode : 'DELIVERY_FAILED'); } catch (Throwable) {}
        }
        if ($error instanceof GestureCatalogException) throw $error;
        throw new GestureCatalogException('Gesture package could not be delivered.', 500, 'PACKAGE_DELIVERY_FAILED');
    } finally {
        if ($temporary) gesture_package_remove_tree((string)$temporary['cleanup_directory']);
    }
}

try {
    if ($method === 'GET') {
        $action = (string)($_GET['action'] ?? 'preferences');
        if ($action === 'preferences') {
            json_out([
                'ok' => true,
                'features' => $features,
                'capabilities' => $capabilities,
                'preferences' => gesture_catalog_preferences_payload($pdo, (int)$actor['id']),
            ]);
        }
        $publicId = trim((string)($_GET['id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $publicId)) throw new GestureCatalogException('Gesture identity is invalid.', 400, 'GESTURE_ID_INVALID');
        $admin = gesture_package_api_bool($_GET['admin'] ?? false);
        if ($action === 'detail') {
            gesture_package_api_feature($features, $admin ? 'admin_package_inspection' : 'editor', 'Gesture package inspection is disabled.');
            if ($admin) security_require_recent_authentication_or_json();
            json_out(['ok' => true, 'features' => $features, 'capabilities' => $capabilities] + gesture_package_editor_detail($pdo, $actor, $publicId, $admin));
        }
        if ($action === 'download') {
            $downloadFeature = ($actor['role'] ?? '') === 'admin' ? 'admin_package_inspection' : 'user_package_download';
            gesture_package_api_feature($features, $downloadFeature, 'Gesture package download is disabled.');
            gesture_package_api_download($pdo, $actor, $publicId);
        }
        throw new GestureCatalogException('Unsupported gesture package action.', 400, 'UNSUPPORTED_ACTION');
    }

    if ($method !== 'POST') json_out(['error' => 'Unsupported method'], 405);
    $action = (string)($_POST['action'] ?? '');
    $admin = $action === 'admin_edit';
    if ($action === 'create') gesture_package_api_feature($features, 'editor', 'Gesture Maker is disabled.');
    elseif ($action === 'edit') gesture_package_api_feature($features, 'editor', 'Gesture editing is disabled.');
    elseif ($admin) {
        gesture_package_api_feature($features, 'admin_media_replacement', 'Admin gesture replacement is disabled.');
        security_require_recent_authentication_or_json();
    } else {
        throw new GestureCatalogException('Unsupported gesture package action.', 400, 'UNSUPPORTED_ACTION');
    }
    if (!empty($_FILES['package'])) gesture_package_api_feature($features, 'user_package_import', 'Gesture package import is disabled.');
    if (!empty($_FILES['audio'])) gesture_package_api_feature($features, 'audio_media', 'Gesture audio media is disabled.');
    if (!empty($_FILES['animation'])) gesture_package_api_feature($features, 'animation_media', 'Gesture animation media is disabled.');
    security_authorize_outside_content_or_json($pdo, $actor, 'gesture_upload', ['source' => $admin ? 'admin-editor' : 'gesture-editor']);
    $fields = [
        'title' => $_POST['title'] ?? '',
        'text' => $_POST['text'] ?? '',
        'creator_credit' => $_POST['creator_credit'] ?? '',
        'catalog_filename' => $_POST['catalog_filename'] ?? '',
        'remove_audio' => gesture_package_api_bool($_POST['remove_audio'] ?? false),
        'remove_poster' => gesture_package_api_bool($_POST['remove_poster'] ?? false),
    ];
    $files = [
        'package' => $_FILES['package'] ?? null,
        'animation' => $_FILES['animation'] ?? null,
        'poster' => $_FILES['poster'] ?? null,
        'audio' => $_FILES['audio'] ?? null,
    ];
    $requestKey = substr(trim((string)($_POST['request_key'] ?? '')), 0, 96);
    if ($action === 'create') json_out(gesture_package_create($pdo, $actor, $fields, $files, $requestKey));
    $publicId = trim((string)($_POST['public_id'] ?? ''));
    json_out(gesture_package_edit($pdo, $actor, $publicId, $fields, $files, (int)($_POST['expected_version'] ?? -1), $requestKey, $admin));
} catch (JsonException) {
    json_out(['error' => 'Gesture package manifest is not valid JSON.', 'error_code' => 'PACKAGE_MANIFEST_INVALID'], 400);
} catch (GestureCatalogException $error) {
    json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
} catch (SecurityPolicyViolation $error) {
    json_out(['error' => $error->getMessage(), 'error_code' => 'SECURITY_POLICY_REFUSED'], $error->httpStatus);
} catch (Throwable) {
    json_out(['error' => 'Gesture package operation failed safely.', 'error_code' => 'GESTURE_PACKAGE_FAILED'], 500);
}
