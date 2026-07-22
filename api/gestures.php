<?php
require_once __DIR__ . '/../includes/base.php';

$pdo = db();
$jsonBody = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $jsonBody = input_json();
}
$requestSession = $jsonBody['session_id'] ?? ($_REQUEST['session_id'] ?? '');
$requestToken = $jsonBody['join_token'] ?? ($_REQUEST['join_token'] ?? '');
$sessionId = resolve_session_id($pdo, $requestSession);
$participant = auth_participant($pdo, $sessionId, $requestToken);
$userId = (int)$participant['user_id'];

function gesture_row_payload(array $row, int $userId): array {
    return gesture_catalog_row_payload($row, $userId);
}

function gesture_mutation_rate_guard(PDO $pdo, int $userId, string $action): void {
    $scope = 'gesture:' . $action;
    $identifier = (string)$userId;
    $status = auth_rate_limit_status($pdo, $scope, $identifier);
    if (!$status['allowed']) {
        json_out(['error' => $status['message'], 'error_code' => 'GESTURE_RATE_LIMITED', 'retry_after' => $status['retry_after']], 429);
    }
    auth_rate_record_failure($pdo, $scope, $identifier);
}

function gesture_request_key(array $body, string $fallbackPrefix, bool $required = false): string {
    $key = trim((string)($body['request_key'] ?? ''));
    if ($key !== '') return substr($key, 0, 96);
    if ($required) throw new GestureCatalogException('A request key is required.', 400, 'REQUEST_KEY_REQUIRED');
    return $fallbackPrefix . '-' . uuid_v4();
}

function package_asset_name(?string $name, string $fallback): string {
    $clean = trim(str_replace('\\', '/', (string)$name));
    if ($clean === '' || str_contains($clean, '/')) return $fallback;
    return basename($clean) ?: $fallback;
}

function clean_gesture_text(string $text, string $fallback): string {
    $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{S}\p{Zs}]/u', '', $text) ?? $text;
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    return $text === '' ? $fallback : $text;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $catalog = (string)($_GET['catalog'] ?? '');
    if ($catalog === 'preferences') {
        try {
            json_out(['ok' => true, 'preferences' => gesture_catalog_preferences_payload($pdo, $userId)]);
        } catch (GestureCatalogException $error) {
            json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
        }
    }
    if (in_array($catalog, ['server', 'personal', 'hidden'], true)) {
        try {
            $result = gesture_catalog_query($pdo, $userId, $catalog, [
                'q' => $_GET['q'] ?? '',
                'page' => $_GET['page'] ?? 1,
                'sort' => $_GET['sort'] ?? null,
            ]);
            $result['owned_count'] = (int)$pdo->query('SELECT COUNT(*) FROM gestures WHERE owner_user_id = ' . $userId . ' AND deleted_at IS NULL')->fetchColumn();
            $result['owned_limit'] = max(0, (int)app_setting($pdo, 'gesture_upload_limit', '50'));
            json_out($result);
        } catch (GestureCatalogException $error) {
            json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
        }
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $ownedStmt = $pdo->prepare('SELECT COUNT(*) FROM gestures WHERE owner_user_id = ? AND deleted_at IS NULL');
    $ownedStmt->execute([$userId]);
    $ownedCount = (int)$ownedStmt->fetchColumn();
    $ownedLimit = max(0, (int)app_setting($pdo, 'gesture_upload_limit', '50'));
    $params = [$userId];
    $where = 'deleted_at IS NULL AND (owner_user_id = ? OR is_public = 1)';
    if ($q !== '') {
        $where .= ' AND (LOWER(name) LIKE ? OR LOWER(gesture_text) LIKE ?)';
        $needle = '%' . strtolower($q) . '%';
        $params[] = $needle;
        $params[] = $needle;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM gestures WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT * FROM gestures
            WHERE $where
            ORDER BY CASE WHEN owner_user_id = ? THEN 0 ELSE 1 END ASC, updated_at DESC, id DESC
            LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$params, $userId]);
    $items = array_map(fn(array $row): array => gesture_row_payload($row, $userId), $stmt->fetchAll());
    json_out([
        'gestures' => $items,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'owned_count' => $ownedCount,
        'owned_limit' => $ownedLimit,
        'has_more' => ($offset + $perPage) < $total,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);

if (str_contains($contentType, 'application/json')) {
    $body = $jsonBody;
    $action = (string)($body['action'] ?? '');
    gesture_mutation_rate_guard($pdo, $userId, $action ?: 'unknown');
    try {
        if ($action === 'set_presentation_preferences') {
            json_out(gesture_catalog_set_presentation_preferences(
                $pdo,
                $userId,
                (array)($body['values'] ?? []),
                (int)($body['expected_version'] ?? -1),
                gesture_request_key($body, 'presentation', true)
            ));
        }
        if ($action === 'set_sort') {
            json_out(gesture_catalog_set_sort($pdo, $userId, (string)($body['catalog'] ?? ''), (string)($body['sort'] ?? ''), (int)($body['expected_version'] ?? -1), gesture_request_key($body, 'sort', true)));
        }
        if ($action === 'set_order') {
            json_out(gesture_catalog_set_order($pdo, $userId, (string)($body['catalog'] ?? ''), (array)($body['ordered_ids'] ?? []), (int)($body['expected_version'] ?? -1), gesture_request_key($body, 'order', true), (string)($body['search'] ?? '')));
        }
        if (in_array($action, ['move_before', 'move_top', 'move_page'], true)) {
            json_out(gesture_catalog_move(
                $pdo,
                $userId,
                (string)($body['catalog'] ?? ''),
                (string)($body['public_id'] ?? ''),
                $action,
                $action === 'move_before' ? ($body['before_id'] ?? null) : ($body['page'] ?? null),
                (int)($body['expected_version'] ?? -1),
                gesture_request_key($body, $action, true),
                (string)($body['search'] ?? '')
            ));
        }
        if ($action === 'reset_position') {
            json_out(gesture_catalog_reset_position(
                $pdo,
                $userId,
                (string)($body['catalog'] ?? ''),
                (string)($body['public_id'] ?? ''),
                (int)($body['expected_version'] ?? -1),
                gesture_request_key($body, 'reset-position', true),
                (string)($body['search'] ?? '')
            ));
        }
        if ($action === 'hide' || $action === 'unhide') {
            json_out(gesture_catalog_hide($pdo, $userId, (string)($body['public_id'] ?? ''), $action === 'hide', (int)($body['expected_version'] ?? -1), gesture_request_key($body, $action, true)));
        }
        if ($action === 'unhide_many') {
            json_out(gesture_catalog_unhide_many(
                $pdo,
                $userId,
                (array)($body['public_ids'] ?? []),
                (int)($body['expected_version'] ?? -1),
                gesture_request_key($body, 'unhide-many', true)
            ));
        }

        $gestureId = (int)($body['gesture_id'] ?? 0);
        $publicId = trim((string)($body['public_id'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM gestures WHERE ' . ($publicId !== '' ? 'public_id = ?' : 'id = ?') . ' AND owner_user_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$publicId !== '' ? $publicId : $gestureId, $userId]);
        $gesture = $stmt->fetch();
        if (!$gesture) throw new GestureCatalogException('Gesture not found.', 404, 'GESTURE_NOT_FOUND');
        $expectedVersion = array_key_exists('expected_version', $body) ? (int)$body['expected_version'] : (int)$gesture['version'];
        $requestKey = gesture_request_key($body, 'compat-' . $action, $action === 'update_metadata');

        if ($action === 'toggle_public') {
            json_out(gesture_catalog_toggle_public($pdo, $userId, (string)$gesture['public_id'], !empty($body['is_public']), $expectedVersion, $requestKey));
        }
        if ($action === 'update_metadata') {
            json_out(gesture_catalog_update_personal_metadata($pdo, $userId, (string)$gesture['public_id'], (array)($body['changes'] ?? []), $expectedVersion, $requestKey));
        }
        if ($action === 'delete') {
            $result = gesture_catalog_delete($pdo, $userId, (string)$gesture['public_id'], $expectedVersion, $requestKey);
            $result['gesture_id'] = (int)$gesture['id'];
            json_out($result);
        }
        throw new GestureCatalogException('Unsupported gesture action.', 400, 'UNSUPPORTED_ACTION');
    } catch (GestureCatalogException $error) {
        json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
    }
}

if (!class_exists('ZipArchive')) json_out(['error' => 'PHP ZipArchive is required to upload gestures.'], 500);
if (empty($_FILES['gesture']) || !is_uploaded_file($_FILES['gesture']['tmp_name'])) {
    json_out(['error' => 'Gesture file required'], 400);
}
security_authorize_outside_content_or_json($pdo, ['id' => $userId], 'gesture_upload', ['session_id' => $sessionId]);
gesture_mutation_rate_guard($pdo, $userId, 'upload');

$file = $_FILES['gesture'];
$name = (string)($file['name'] ?? 'gesture.agst');
$size = (int)($file['size'] ?? 0);
if ($size <= 0) json_out(['error' => 'Gesture file was empty'], 400);
if ($size > 30 * 1024 * 1024) json_out(['error' => 'Gesture file is too large'], 400);
if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'agst') {
    json_out(['error' => 'Upload a .agst gesture file'], 400);
}

$ownedLimit = max(0, (int)app_setting($pdo, 'gesture_upload_limit', '50'));
$ownedStmt = $pdo->prepare('SELECT COUNT(*) FROM gestures WHERE owner_user_id = ? AND deleted_at IS NULL');
$ownedStmt->execute([$userId]);
if ((int)$ownedStmt->fetchColumn() >= $ownedLimit) {
    json_out(['error' => 'Gesture limit reached. Remove some gestures to make room.'], 400);
}

$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) json_out(['error' => 'Gesture package could not be opened'], 400);
if ($zip->numFiles < 1 || $zip->numFiles > 20) {
    $zip->close();
    json_out(['error' => 'Gesture package contains too many files'], 400);
}
$archiveUncompressedBytes = 0;
for ($archiveIndex = 0; $archiveIndex < $zip->numFiles; $archiveIndex++) {
    $archiveStat = $zip->statIndex($archiveIndex);
    $archiveName = package_asset_name((string)($archiveStat['name'] ?? ''), '');
    $archiveSize = (int)($archiveStat['size'] ?? 0);
    $archiveCompressed = max(1, (int)($archiveStat['comp_size'] ?? 0));
    if ($archiveName === '' || $archiveSize < 0 || $archiveSize > 25 * 1024 * 1024 || $archiveSize > $archiveCompressed * 200) {
        $zip->close();
        json_out(['error' => 'Gesture package contains an unsafe archive entry'], 400);
    }
    $archiveUncompressedBytes += $archiveSize;
    if ($archiveUncompressedBytes > 35 * 1024 * 1024) {
        $zip->close();
        json_out(['error' => 'Gesture package expands beyond the allowed size'], 400);
    }
}
$tocRaw = $zip->getFromName('toc.json');
$legacyMetaRaw = $tocRaw === false ? $zip->getFromName('meta.json') : false;
$manifestRaw = $tocRaw !== false ? $tocRaw : $legacyMetaRaw;
if ($manifestRaw === false) {
    $zip->close();
    json_out(['error' => 'Gesture package must include toc.json'], 400);
}
$manifest = json_decode($manifestRaw, true);
if (!is_array($manifest)) {
    $zip->close();
    json_out(['error' => 'Gesture toc.json was not readable'], 400);
}
$animationName = package_asset_name($manifest['animation'] ?? 'animation.gif', 'animation.gif');
$audioName = package_asset_name($manifest['audio'] ?? 'audio.mp3', 'audio.mp3');
$gifBytes = $zip->getFromName($animationName);
$audioBytes = $zip->getFromName($audioName);
$zip->close();

if ($gifBytes === false) json_out(['error' => 'Gesture package must include the GIF referenced by toc.json'], 400);
if (strncmp($gifBytes, 'GIF', 3) !== 0 || @getimagesizefromstring($gifBytes) === false) json_out(['error' => 'Gesture animation must be a valid GIF'], 400);
if (strlen($gifBytes) > 25 * 1024 * 1024) json_out(['error' => 'Gesture animation is too large'], 400);

$gestureName = trim((string)($manifest['name'] ?? pathinfo($name, PATHINFO_FILENAME)));
if ($gestureName === '') $gestureName = 'Uploaded Gesture';
$gestureName = gesture_catalog_clean_text($gestureName, 80, 'Uploaded Gesture');
$gestureText = clean_gesture_text((string)($manifest['text'] ?? $manifest['fallbackText'] ?? $manifest['gestureText'] ?? $gestureName), $gestureName);
if ($gestureText === '') $gestureText = $gestureName;
$gestureName = function_exists('mb_substr') ? mb_substr($gestureName, 0, 80, 'UTF-8') : substr($gestureName, 0, 80);
$gestureText = function_exists('mb_substr') ? mb_substr($gestureText, 0, 180, 'UTF-8') : substr($gestureText, 0, 180);
$catalogFilename = gesture_catalog_filename_stem(pathinfo($name, PATHINFO_FILENAME), 'gesture');
$originalFilename = gesture_catalog_original_filename($name, 'gesture');
try {
    gesture_catalog_assert_filename_available($pdo, $userId, $catalogFilename);
} catch (GestureCatalogException $error) {
    json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
}
$uploaderStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
$uploaderStmt->execute([$userId]);
$uploaderName = (string)($uploaderStmt->fetchColumn() ?: 'Unknown creator');
$creatorCredit = gesture_catalog_clean_text((string)($manifest['creator'] ?? $manifest['author'] ?? $uploaderName), 120, $uploaderName);

if ($audioBytes !== false && strlen($audioBytes) > 0) {
    $audioPrefix = substr($audioBytes, 0, 32);
    $validMp3 = str_starts_with($audioPrefix, 'ID3') || (strlen($audioPrefix) >= 2 && ord($audioPrefix[0]) === 0xFF && (ord($audioPrefix[1]) & 0xE0) === 0xE0);
    if (!$validMp3 || strlen($audioBytes) > 10 * 1024 * 1024) json_out(['error' => 'Gesture audio must be a valid MP3 under 10 MB'], 400);
}

$publicId = uuid_v4();
$uploadDir = __DIR__ . '/../assets/uploads/gestures';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
$gifFile = $publicId . '.gif';
$audioFile = $publicId . '.mp3';
if (file_put_contents($uploadDir . '/' . $gifFile, $gifBytes) === false) {
    json_out(['error' => 'Could not save gesture animation'], 500);
}
$audioPath = null;
$audioSilent = 1;
if ($audioBytes !== false && strlen($audioBytes) > 0) {
    if (file_put_contents($uploadDir . '/' . $audioFile, $audioBytes) === false) {
        @unlink($uploadDir . '/' . $gifFile);
        json_out(['error' => 'Could not save gesture audio'], 500);
    }
    $audioPath = '/assets/uploads/gestures/' . $audioFile;
    $audioSilent = strlen($audioBytes) <= 4096 ? 1 : 0;
}

$gifPath = '/assets/uploads/gestures/' . $gifFile;
security_assert_storage_destination('gesture_upload', $gifPath);
if ($audioPath !== null) security_assert_storage_destination('gesture_upload', $audioPath);
$stmt = $pdo->prepare(
    'INSERT INTO gestures (public_id, owner_user_id, name, gesture_text, gif_path, audio_path, audio_is_silent, is_public, file_size, '
    . 'original_filename, catalog_filename, catalog_filename_key, active_catalog_key, title, creator_credit, uploaded_by_user_id, '
    . 'original_uploaded_at, content_updated_at, metadata_updated_at, visibility_changed_at, version, legacy_metadata) '
    . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,0)'
);
try {
    $stmt->execute([$publicId, $userId, $gestureName, $gestureText, $gifPath, $audioPath, $audioSilent, 0, $size, $originalFilename, $catalogFilename, gesture_catalog_filename_key($catalogFilename), 'active', $gestureName, $creatorCredit, $userId]);
} catch (Throwable $error) {
    @unlink($uploadDir . '/' . $gifFile);
    if ($audioPath !== null) @unlink($uploadDir . '/' . $audioFile);
    json_out(['error' => 'Could not save gesture metadata. Refresh and try again.', 'error_code' => 'GESTURE_SAVE_FAILED'], 500);
}
$id = (int)$pdo->lastInsertId();
$stmt = $pdo->prepare('SELECT * FROM gestures WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
json_out(['ok' => true, 'gesture' => gesture_row_payload($stmt->fetch(), $userId)]);
