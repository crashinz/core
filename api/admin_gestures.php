<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$me = require_staff(['admin']);
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    try {
        json_out(gesture_catalog_query($pdo, (int)$me['id'], 'server', [
            'q' => $_GET['q'] ?? '',
            'page' => $_GET['page'] ?? 1,
            'sort' => $_GET['sort'] ?? 'last_uploaded',
        ], true));
    } catch (GestureCatalogException $error) {
        json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Unsupported method'], 405);
security_require_recent_authentication_or_json();
$body = input_json();
$action = (string)($body['action'] ?? '');
$rateScope = 'gesture:admin-' . ($action ?: 'unknown');
$rateStatus = auth_rate_limit_status($pdo, $rateScope, (string)$me['id']);
if (!$rateStatus['allowed']) {
    json_out(['error' => $rateStatus['message'], 'error_code' => 'GESTURE_RATE_LIMITED', 'retry_after' => $rateStatus['retry_after']], 429);
}
auth_rate_record_failure($pdo, $rateScope, (string)$me['id']);

try {
    if ($action !== 'update_metadata') {
        throw new GestureCatalogException('Unsupported Admin gesture action.', 400, 'UNSUPPORTED_ACTION');
    }
    $requestKey = trim((string)($body['request_key'] ?? ''));
    if ($requestKey === '') throw new GestureCatalogException('A request key is required.', 400, 'REQUEST_KEY_REQUIRED');
    json_out(gesture_catalog_admin_update(
        $pdo,
        $me,
        (string)($body['public_id'] ?? ''),
        (array)($body['changes'] ?? []),
        (int)($body['expected_version'] ?? -1),
        substr($requestKey, 0, 96)
    ));
} catch (GestureCatalogException $error) {
    json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
}
