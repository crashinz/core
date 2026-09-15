<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('library-duplicates', 'LIBRARY_SCAN_FAILED', 'The library scan could not finish. Try again.');
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/library_duplicate_review.php';
$actor = require_staff(['admin']);
security_protect_private_response();
security_require_recent_authentication_or_json();
$pdo = db();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_out(library_duplicate_scan($pdo, $actor, (string)($_GET['kind'] ?? ''), substr((string)($_GET['after'] ?? ''), 0, 40)));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error'=>'GET or POST required'], 405);
    csrf_protect_post();
    $body = input_json();
    if (($body['action'] ?? '') === 'delete_personal') {
        $id = (string)($body['public_id'] ?? '');
        $guard = static fn() => library_duplicate_delete_guard($pdo, $actor, 'gesture', $id, $body['duplicate_review'] ?? null);
        json_out(gesture_catalog_delete($pdo, (int)$actor['id'], $id, (int)($body['expected_version'] ?? -1), substr((string)($body['request_key'] ?? ''), 0, 96), $guard));
    }
    json_out(library_duplicate_verify($pdo, $actor, (string)($body['kind'] ?? ''), (array)($body['target'] ?? []), (array)($body['keep'] ?? [])));
} catch (GestureCatalogException $error) {
    json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
} catch (CustomEmojiException $error) {
    json_out(['error'=>$error->getMessage()], $error->httpStatus);
}
