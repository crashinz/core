<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/upload_duplicates.php';
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/nameplate_policy.php';

$me = require_user();
$pdo = db();
security_protect_private_response();
$userId = (int)$me['id'];
$kind = (string)($_POST['kind'] ?? $_GET['kind'] ?? 'avatar');
if (!in_array($kind, ['avatar', 'nameplate'], true)) json_out(['error' => 'Unknown image library.'], 400);
$sourceRole = $kind;
$libraryPrefix = $kind . '_library.';
$delivery = moderation_safety_delivery_policy($pdo, 'avatar');
if (($delivery['effectiveMode'] ?? '') !== 'server-stored') json_out(['error' => 'Server-stored avatars are disabled.'], 403);
if (!empty(moderation_trust_policy($pdo)['effectiveEnabled'])) moderation_identity_require_capability($pdo, $userId, 'upload-avatar');
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? 'list'));
$shareKey = static fn(string $id): string => $libraryPrefix . 'shared.' . $id;

if ($method === 'POST') {
    csrf_protect_post();
    security_authorize_outside_content_or_json($pdo, $me, 'avatar_upload');
    if (in_array($action, ['upload_shared', 'upload_shared_batch'], true)) {
        if (($me['role'] ?? '') !== 'admin') json_out(['error' => 'Administrator required for folder publishing.'], 403);
        $batch = $action === 'upload_shared_batch';
        $files = [];
        if ($batch) {
            $uploads = $_FILES['avatars'] ?? [];
            $names = $uploads['name'] ?? [];
            if (!is_array($names) || count($names) < 1 || count($names) > 10) json_out(['error' => 'Choose between 1 and 10 images per upload batch.'], 400);
            foreach (array_keys($names) as $index) {
                $file = [];
                foreach (['name','tmp_name','error','size','type'] as $field) $file[$field] = $uploads[$field][$index] ?? null;
                $files[] = $file;
            }
            if (array_sum(array_map(static fn(array $file): int => max(0, (int)$file['size']), $files)) > 67108864) json_out(['error' => 'The image batch exceeds the allowed request size.'], 413);
        } else {
            $files[] = $_FILES['avatar'] ?? [];
        }
        $store = static function (array $file) use ($pdo, $userId, $kind, $libraryPrefix, $shareKey): array {
        $temporary = (string)($file['tmp_name'] ?? '');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($temporary)) throw new InvalidArgumentException('Choose a valid avatar image.');
        if ($kind === 'nameplate') {
            $inspection = nameplate_upload_inspect($pdo, $temporary);
            if (isset($inspection['error'])) throw new InvalidArgumentException($inspection['error']);
            $mime = $inspection['mime'];
            $extensions = [$mime => $inspection['extension']];
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
            $extensions = ['image/gif' => 'gif', 'image/webp' => 'webp'];
            $types = ['image/gif' => IMAGETYPE_GIF, 'image/webp' => IMAGETYPE_WEBP];
            $dimensions = @getimagesize($temporary);
            $policy = avatar_size_policy($pdo);
            if (!isset($extensions[$mime]) || !$dimensions || $dimensions[2] !== $types[$mime]) throw new InvalidArgumentException('Use a valid prepared GIF or WebP image.');
            if ($policy['avatarMaxBytes'] !== null && filesize($temporary) > $policy['avatarMaxBytes']) throw new InvalidArgumentException('Avatar exceeds the configured file-size limit.');
            if ($dimensions[0] < AVATAR_UPLOAD_MIN_DIMENSION_PX || $dimensions[1] < AVATAR_UPLOAD_MIN_DIMENSION_PX
                || $dimensions[0] > $policy['avatarUploadMaxWidthPx'] || $dimensions[1] > $policy['avatarUploadMaxHeightPx']) throw new InvalidArgumentException('Avatar dimensions are outside the configured limits.');
        }
        $transaction = database_transaction_begin($pdo, true);
        $destination = null;
        try {
        upload_duplicate_lock($pdo, $kind, $userId);
        $duplicate = upload_duplicate_find_image($pdo, $userId, $kind, $temporary);
        if ($duplicate !== null) {
            database_transaction_commit($pdo, $transaction);
            return $duplicate;
        }
        $public = ($kind === 'nameplate' ? '/assets/uploads/nameplates/nameplate-' : '/assets/uploads/avatars/') . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
        security_assert_storage_destination($kind === 'nameplate' ? 'nameplate_upload' : 'avatar_upload', $public);
        $destination = dirname(__DIR__) . $public;
        if ($kind === 'nameplate') {
            $directory = dirname($destination);
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('The image could not be stored.');
        }
        if (!move_uploaded_file($temporary, $destination)) throw new RuntimeException('The image could not be stored.');
            $id = $kind === 'nameplate'
                ? server_media_register_nameplate($pdo, $userId, $public, $destination, $mime)
                : server_media_register_avatar($pdo, $userId, $public, $destination, $mime, false);
            set_app_setting($pdo, $shareKey($id), '1');
            set_app_setting($pdo, $libraryPrefix . 'name.' . $id, mb_substr(basename(str_replace('\\', '/', (string)($file['name'] ?? 'Avatar'))), 0, 180));
            set_app_setting($pdo, $libraryPrefix . 'section.' . $id, mb_substr(trim((string)($_POST['section'] ?? '')), 0, 80));
            database_transaction_commit($pdo, $transaction);
        } catch (Throwable $error) {
            database_transaction_rollback($pdo, $transaction);
            if ($destination !== null) @unlink($destination);
            throw $error;
        }
        return ['ok' => true, 'id' => $id];
        };
        $results = [];
        foreach ($files as $index => $file) {
            try { $result = $store($file); }
            catch (InvalidArgumentException $error) {
                if (!$batch) json_out(['error' => $error->getMessage()], 400);
                $result = ['ok' => false, 'error' => $error->getMessage()];
            } catch (Throwable $error) {
                if (!$batch) throw $error;
                $result = ['ok' => false, 'error' => 'The image could not be stored. Try again.'];
            }
            if (!$batch) json_out($result);
            $results[] = ['index' => $index] + $result;
        }
        json_out(['ok' => true, 'results' => $results]);
    }
    if (!in_array($action, ['organize', 'share', 'delete', 'remove_community'], true)) {
        json_out(['error' => 'Unknown image library action.'], 400);
    }
    $id = (string)($_POST['id'] ?? '');
    $transaction = database_transaction_begin($pdo, true);
    try {
        if (isset($_POST['duplicate_review'])) {
            require_once __DIR__ . '/../includes/library_duplicate_review.php';
            try { library_duplicate_delete_guard($pdo, $me, $kind, $id, $_POST['duplicate_review']); }
            catch (CustomEmojiException $error) { throw new ServerMediaException($error->getMessage(), 'DUPLICATE_REVIEW_CHANGED', $error->httpStatus); }
        }
        // Mutations and first-copy selection lock the same asset before reading sharing/removal state.
        $lockSuffix = db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : '';
        $query = $pdo->prepare("SELECT public_id,uploader_user_id FROM server_media_assets WHERE public_id=? AND source_owner='avatar' AND source_role=? AND category='avatar' AND status='active' AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP) LIMIT 1" . $lockSuffix);
        $query->execute([$id, $sourceRole]);
        $asset = $query->fetch();
        $deletedKey = $libraryPrefix . 'deleted.' . $id;
        if (!$asset || app_setting($pdo, $deletedKey, '0') === '1') {
            throw new ServerMediaException('The image is no longer in this library.', 'LIBRARY_ENTRY_UNAVAILABLE', 404);
        }
        $owner = (int)$asset['uploader_user_id'];
        $isOwner = $owner === $userId;
        $isAdmin = ($me['role'] ?? '') === 'admin';
        $shared = app_setting($pdo, $shareKey($id), '0') === '1';
        if (in_array($action, ['share', 'delete'], true) && !$isOwner) {
            throw new ServerMediaException('Only the uploader can change this private library entry.', 'LIBRARY_OWNER_REQUIRED', 403);
        }
        if ($action === 'remove_community' && (!$isAdmin || $isOwner || !$shared)) {
            throw new ServerMediaException('An administrator may only remove another uploader\'s currently shared entry.', 'LIBRARY_ADMIN_SHARED_REQUIRED', 403);
        }
        if ($action === 'organize' && !$isOwner && !($isAdmin && $shared)) {
            throw new ServerMediaException('You cannot organize this image.', 'LIBRARY_OWNER_REQUIRED', 403);
        }
        if ($action === 'organize') {
            $name = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', (string)($_POST['name'] ?? '')) ?? '');
            $section = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', (string)($_POST['section'] ?? '')) ?? '');
            if (mb_strlen($name) > 180 || mb_strlen($section) > 80) {
                throw new ServerMediaException('Use at most 180 characters for a name and 80 for a section.', 'LIBRARY_METADATA_INVALID', 400);
            }
            set_app_setting($pdo, $libraryPrefix . 'name.' . $id, $name);
            set_app_setting($pdo, $libraryPrefix . 'section.' . $id, $section);
        } elseif ($action === 'delete') {
            set_app_setting($pdo, $deletedKey, '1');
            set_app_setting($pdo, $shareKey($id), '0');
        } else {
            set_app_setting($pdo, $shareKey($id), $action === 'share' && ($_POST['shared'] ?? '') === '1' ? '1' : '0');
        }
        $pdo->prepare('UPDATE server_media_assets SET updated_at=CURRENT_TIMESTAMP WHERE public_id=?')->execute([$id]);
        database_transaction_commit($pdo, $transaction);
    } catch (ServerMediaException $error) {
        database_transaction_rollback($pdo, $transaction);
        json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
    json_out($action === 'organize' ? ['ok' => true, 'name' => $name, 'section' => $section] : ['ok' => true]);
}
if ($method !== 'GET') json_out(['error' => 'Method not allowed.'], 405);

// Existing private inventory is never made public merely by enabling the picker.
$sharedSql = "EXISTS (SELECT 1 FROM app_settings s WHERE s.setting_key="
    . (db_uses_mysql_syntax($pdo) ? "CONCAT('{$libraryPrefix}shared.',a.public_id)" : "'{$libraryPrefix}shared.' || a.public_id")
    . " AND s.value='1')";
$removedSql = "EXISTS (SELECT 1 FROM app_settings removed WHERE removed.setting_key="
    . (db_uses_mysql_syntax($pdo) ? "CONCAT('{$libraryPrefix}deleted.',a.public_id)" : "'{$libraryPrefix}deleted.' || a.public_id")
    . " AND removed.value='1')";
$where = "a.source_owner='avatar' AND a.source_role='$sourceRole' AND a.category='avatar' AND a.status='active' AND (a.expires_at IS NULL OR a.expires_at>CURRENT_TIMESTAMP) AND NOT ($removedSql)";
if ($action === 'image') {
    $query = $pdo->prepare("SELECT a.* FROM server_media_assets a WHERE $where AND a.public_id=? AND (a.uploader_user_id=? OR $sharedSql) LIMIT 1");
    $query->execute([(string)($_GET['id'] ?? ''), $userId]);
    $asset = $query->fetch();
    $path = $asset ? realpath((string)$asset['storage_path']) : false;
    $directories = $kind === 'nameplate' ? ['avatars', 'nameplates'] : ['avatars'];
    $withinRoot = false;
    foreach ($directories as $directory) {
        $root = realpath(dirname(__DIR__) . '/assets/uploads/' . $directory);
        if ($path && $root && str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            $withinRoot = true;
            break;
        }
    }
    if (!$asset || !$path || !$withinRoot || !is_file($path)) json_out(['error' => 'Avatar unavailable.'], 404);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/gif','image/webp','image/png','image/jpeg'], true) || !@getimagesize($path)) json_out(['error' => 'Avatar unavailable.'], 404);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
if ($action !== 'list') json_out(['error' => 'Unknown avatar library action.'], 400);
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * 40;
$community = ($_GET['view'] ?? 'mine') === 'community';
$filter = $community ? $sharedSql : 'a.uploader_user_id=?';
if (($_GET['view'] ?? '') === 'private') $filter .= " AND NOT ($sharedSql)";
$concat = static fn(string $prefix): string => db_uses_mysql_syntax($pdo) ? "CONCAT('$prefix',a.public_id)" : "'$prefix' || a.public_id";
$joins = ' LEFT JOIN app_settings names ON names.setting_key=' . $concat($libraryPrefix . 'name.')
    . ' LEFT JOIN app_settings sections ON sections.setting_key=' . $concat($libraryPrefix . 'section.');
$parameters = $community ? [] : [$userId];
$sections = $pdo->prepare("SELECT DISTINCT sections.value FROM server_media_assets a $joins WHERE $where AND $filter AND sections.value<>'' ORDER BY sections.value LIMIT 200");
$sections->execute($parameters);
$sectionNames = $sections->fetchAll(PDO::FETCH_COLUMN);
$section = mb_substr(trim((string)($_GET['section'] ?? '')), 0, 80);
if ($section !== '') { $filter .= ' AND sections.value=?'; $parameters[] = $section; }
$sort = match ((string)($_GET['sort'] ?? 'uploaded')) {
    'name' => "LOWER(COALESCE(NULLIF(names.value,''),a.safe_name)) ASC,a.id DESC",
    'modified' => 'a.updated_at DESC,a.id DESC',
    'oldest' => 'a.created_at ASC,a.id ASC',
    default => 'a.created_at DESC,a.id DESC',
};
$query = $pdo->prepare("SELECT a.public_id,a.uploader_user_id,a.created_at,a.updated_at,COALESCE(NULLIF(names.value,''),a.safe_name) AS display_name,sections.value AS section,($sharedSql) AS shared FROM server_media_assets a $joins WHERE $where AND $filter ORDER BY $sort LIMIT 41 OFFSET $offset");
$query->execute($parameters);
$rows = $query->fetchAll();
$more = count($rows) > 40;
$items = [];
foreach (array_slice($rows, 0, 40) as $row) {
    $items[] = ['id' => $row['public_id'], 'name' => $row['display_name'], 'createdAt' => $row['created_at'], 'modifiedAt' => $row['updated_at'], 'section' => $row['section'] ?: '',
        'mine' => (int)$row['uploader_user_id'] === $userId, 'canOrganize' => (int)$row['uploader_user_id'] === $userId || ($me['role'] ?? '') === 'admin', 'shared' => !empty($row['shared']), 'canRemoveFromCommunity' => (int)$row['uploader_user_id'] !== $userId && ($me['role'] ?? '') === 'admin' && !empty($row['shared'])];
}
json_out(['items' => $items, 'sections' => $sectionNames, 'hasMore' => $more, 'canPublishFolder' => ($me['role'] ?? '') === 'admin']);
