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
    $capability = gesture_capability_policy($pdo);
    if (empty($capability['effective']['allow_gestures'])) {
        json_out(
            gesture_catalog_exception_payload(new GestureCatalogException(
                'Allow gestures is disabled through shared Settings.',
                403,
                'GESTURES_DISABLED'
            )),
            403
        );
    }
    $catalogPredicates = [];
    $params = [];
    if (!empty($capability['effective']['allow_personal_gestures'])) {
        $catalogPredicates[] = 'owner_user_id = ?';
        $params[] = $userId;
    }
    if (!empty($capability['effective']['allow_server_gestures'])) {
        $catalogPredicates[] = '(is_public = 1 AND owner_user_id <> ?)';
        $params[] = $userId;
    }
    $where = 'deleted_at IS NULL AND (' . ($catalogPredicates ? implode(' OR ', $catalogPredicates) : '1 = 0') . ')';
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
    $part4 = gesture_part4_feature_flags($pdo);
    $items = array_map(
        fn(array $row): array => gesture_capability_project_catalog_payload(
            $pdo,
            gesture_row_payload($row, $userId),
            false,
            $capability,
            $part4
        ),
        $stmt->fetchAll()
    );
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
        if ($action === 'hide_sender_media' || $action === 'show_sender_media') {
            json_out(gesture_catalog_set_sender_media_hidden(
                $pdo,
                $userId,
                (int)($body['target_user_id'] ?? 0),
                $action === 'hide_sender_media',
                (int)($body['expected_version'] ?? -1),
                gesture_request_key($body, 'sender-media-visibility', true)
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

if (empty($_FILES['gesture'])) json_out(['error' => 'Gesture file required'], 400);
security_authorize_outside_content_or_json($pdo, ['id' => $userId], 'gesture_upload', ['session_id' => $sessionId, 'source' => 'legacy-picker-adapter']);
gesture_mutation_rate_guard($pdo, $userId, 'upload');
$features = gesture_part4_feature_flags($pdo);
if (empty($features['user_package_import'])) json_out(['error' => 'Gesture package import is disabled.', 'error_code' => 'GESTURE_PART4_FEATURE_DISABLED'], 403);
$actorStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$actorStmt->execute([$userId]);
$actor = $actorStmt->fetch();
if (!$actor) json_out(['error' => 'Authentication is required.'], 401);
$requestKey = trim((string)($_POST['request_key'] ?? ''));
if ($requestKey === '') $requestKey = 'legacy-upload-' . bin2hex(random_bytes(12));
try {
    json_out(gesture_package_create($pdo, $actor, [], ['package' => $_FILES['gesture']], substr($requestKey, 0, 96)));
} catch (JsonException) {
    json_out(['error' => 'Gesture package manifest is not valid JSON.', 'error_code' => 'PACKAGE_MANIFEST_INVALID'], 400);
} catch (GestureCatalogException $error) {
    json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
}
