<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$me = require_user();
$pdo = db();
$isStaff = in_array((string)$me['role'], ['admin', 'developer'], true);

function runtime_issue_api_error(Throwable $error): never
{
    $status = $error instanceof InvalidArgumentException ? 400 : 422;
    json_out(['error' => $error->getMessage()], $status);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'config');
    if ($action === 'config') {
        json_out(['screenshots' => [
            'enabled' => app_setting($pdo, 'diagnostic_screenshots_enabled', '0') === '1',
            'retentionDays' => (int)app_setting($pdo, 'diagnostic_screenshot_retention_days', '0'),
        ]]);
    }
    if ($action === 'screenshot') {
        $record = runtime_issue_screenshot_record($pdo, (string)($_GET['id'] ?? ''));
        if (!$record) json_out(['error' => 'Screenshot not found.'], 404);
        if (!$isStaff && (int)$record['owner_user_id'] !== (int)$me['id']) json_out(['error' => 'Forbidden'], 403);
        $path = runtime_issue_private_root() . DIRECTORY_SEPARATOR . basename((string)$record['storage_name']);
        if (!is_file($path)) json_out(['error' => 'Screenshot file not found.'], 404);
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="diagnostic-' . e((string)$record['public_id']) . '.png"');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }
    if (!$isStaff) json_out(['error' => 'Forbidden'], 403);
    if ($action === 'list') json_out(['issues' => runtime_issue_list($pdo, isset($_GET['status']) ? (string)$_GET['status'] : null)]);
    $issueId = (int)($_GET['issue_id'] ?? 0);
    if ($issueId < 1) json_out(['error' => 'Issue required.'], 400);
    if ($action === 'detail') {
        $detail = runtime_issue_detail($pdo, $issueId);
        if (!$detail) json_out(['error' => 'Issue not found.'], 404);
        json_out($detail);
    }
    if ($action === 'bundle') json_out(['bundle' => runtime_issue_support_bundle($pdo, $issueId)]);
    json_out(['error' => 'Unknown action'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);

$body = input_json();
$action = (string)($body['action'] ?? 'submit');
try {
    if ($action === 'submit') {
        $result = runtime_issue_submit($pdo, (int)$me['id'], $body);
        json_out(['ok' => true] + $result);
    }
    if ($action === 'screenshot') {
        $result = runtime_issue_store_screenshot($pdo, (int)($body['issue_id'] ?? 0), (int)($body['occurrence_id'] ?? 0), (int)$me['id'], (string)($body['data_url'] ?? ''));
        json_out(['ok' => true, 'screenshot' => $result]);
    }
    if ($action === 'delete_screenshot') {
        $deleted = runtime_issue_delete_screenshot($pdo, (string)($body['id'] ?? ''), (int)$me['id'], (string)$me['role'] === 'admin');
        if (!$deleted) json_out(['error' => 'Screenshot not found.'], 404);
        if ((string)$me['role'] === 'admin') log_tool($pdo, (int)$me['id'], 'runtime_issue_screenshot_delete', null, null, 'Deleted censored diagnostic screenshot');
        json_out(['ok' => true]);
    }
    if ($action === 'update_status') {
        if (!$isStaff) json_out(['error' => 'Forbidden'], 403);
        $issueId = (int)($body['issue_id'] ?? 0);
        $status = (string)($body['status'] ?? '');
        $detail = runtime_issue_update_status($pdo, $issueId, $status, (int)$me['id'], (string)($body['reason'] ?? ''), (string)($body['verification_reference'] ?? ''));
        log_tool($pdo, (int)$me['id'], 'runtime_issue_status_update', null, null, "Issue {$issueId} changed to {$status}");
        json_out(['ok' => true] + $detail);
    }
    if ($action === 'cleanup') {
        if ((string)$me['role'] !== 'admin') json_out(['error' => 'Forbidden'], 403);
        $count = runtime_issue_cleanup_screenshots($pdo);
        log_tool($pdo, (int)$me['id'], 'runtime_issue_screenshot_cleanup', null, null, "Deleted {$count} expired diagnostic screenshots");
        json_out(['ok' => true, 'deleted' => $count]);
    }
} catch (Throwable $error) {
    runtime_issue_api_error($error);
}
json_out(['error' => 'Unknown action'], 400);
