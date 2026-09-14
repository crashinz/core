<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('runtime-issues', 'RUNTIME_ISSUES_UNAVAILABLE', 'Diagnostics are temporarily unavailable. Please try again.');
define('CHATSPACE_SQLITE_POLL_REQUEST', ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET');
require_once __DIR__ . '/../includes/base.php';

$me = require_user();
$pdo = db();
$runtimeIssueCapabilities = runtime_issue_capability_projection($pdo, (int)$me['id']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && session_status() === PHP_SESSION_ACTIVE) session_write_close();

function runtime_issue_api_error(Throwable $error): never
{
    if ($error instanceof RuntimeIssueException
        || $error instanceof RuntimeAuditException
        || $error instanceof RuntimeDiagnosticPolicyException
        || $error instanceof ModerationSafetyException
        || $error instanceof ModerationIdentityPolicyException
        || $error instanceof NetworkPrivacyException) {
        json_out([
            'error' => $error->getMessage(),
            'code' => $error->errorCode,
            'projection' => $error->projection ?: null,
        ], $error->httpStatus);
    }
    if ($error instanceof InvalidArgumentException) {
        json_out(['error' => $error->getMessage()], 400);
    }
    // Unexpected failures belong to the correlated, privacy-safe API boundary.
    throw $error;
}

try {
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'config');
    if ($action === 'config') {
        json_out([
            'collection' => runtime_diagnostic_policy_projection($pdo),
            'capabilities' => $runtimeIssueCapabilities,
            'statusCatalog' => runtime_issue_status_catalog(),
            'auditBaseline' => runtime_issue_audit_baseline($pdo),
            'activeAuditRun' => runtime_audit_active_projection($pdo),
            'screenshots' => [
                'enabled' => app_setting($pdo, 'diagnostic_screenshots_enabled', '0') === '1',
                'retentionDays' => corechat_limit_value($pdo, 'diagnostic_screenshot_retention_days', 0),
                'retentionEnforced' => corechat_limit_is_enforced($pdo, 'diagnostic_screenshot_retention_days'),
            ],
        ]);
    }
    if ($action === 'screenshot') {
        $record = runtime_issue_screenshot_record($pdo, (string)($_GET['id'] ?? ''));
        if (!$record) json_out(['error' => 'Screenshot not found.'], 404);
        if ((int)$record['owner_user_id'] !== (int)$me['id']) {
            runtime_issue_require_capability($pdo, (int)$me['id'], 'view-runtime-issues');
        }
        $path = runtime_issue_private_root() . DIRECTORY_SEPARATOR . basename((string)$record['storage_name']);
        if (!is_file($path)) json_out(['error' => 'Screenshot file not found.'], 404);
        security_protect_private_response();
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="diagnostic-' . e((string)$record['public_id']) . '.png"');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }
    if ($action === 'list') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'view-runtime-issues');
        json_out(runtime_issue_query(
            $pdo,
            isset($_GET['status']) ? (string)$_GET['status'] : null,
            isset($_GET['severity']) ? (string)$_GET['severity'] : null,
            (int)($_GET['page'] ?? 1),
            (int)($_GET['per_page'] ?? 50),
            !empty($_GET['since_baseline']) && (string)$_GET['since_baseline'] !== '0'
        ));
    }
    if ($action === 'audit_baseline') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'view-runtime-issues');
        json_out(['auditBaseline' => runtime_issue_audit_baseline($pdo)]);
    }
    if ($action === 'retention') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-evidence');
        json_out([
            'retention' => runtime_diagnostic_retention_projection($pdo),
            'impactPreview' => runtime_diagnostic_retention_preview($pdo),
        ]);
    }
    if ($action === 'collection_export_preview') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'export-runtime-issues');
        json_out(['preview' => runtime_issue_collection_export_preview(
            $pdo,
            isset($_GET['status']) ? (string)$_GET['status'] : null,
            isset($_GET['severity']) ? (string)$_GET['severity'] : null,
            !empty($_GET['since_baseline']) && (string)$_GET['since_baseline'] !== '0'
        )]);
    }
    if ($action === 'audit_runs') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'view-runtime-issues');
        json_out(runtime_audit_list($pdo, (int)($_GET['limit'] ?? 50)));
    }
    if ($action === 'audit_run_detail') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'view-runtime-issues');
        $detail = runtime_audit_detail($pdo, (string)($_GET['audit_run_id'] ?? ''));
        if (!$detail) json_out(['error' => 'Audit run not found.'], 404);
        json_out($detail);
    }
    if ($action === 'audit_run_export_preview') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'export-runtime-issues');
        json_out(['preview' => runtime_audit_export_preview($pdo, (string)($_GET['audit_run_id'] ?? ''))]);
    }
    $issueId = (int)($_GET['issue_id'] ?? 0);
    if ($issueId < 1) json_out(['error' => 'Issue required.'], 400);
    if ($action === 'detail') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'view-runtime-issues');
        $detail = runtime_issue_detail($pdo, $issueId);
        if (!$detail) json_out(['error' => 'Issue not found.'], 404);
        json_out($detail);
    }
    if (in_array($action, ['bundle', 'export_preview'], true)) {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'export-runtime-issues');
        $kind = $action === 'bundle'
            ? 'support-bundle'
            : (string)($_GET['kind'] ?? 'support-bundle');
        json_out(['preview' => runtime_issue_export_preview($pdo, $issueId, $kind)]);
    }
    if ($action === 'deletion_preview') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-evidence');
        json_out(['preview' => runtime_issue_deletion_preview($pdo, $issueId)]);
    }
    json_out(['error' => 'Unknown action'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);

$body = input_json();
$action = (string)($body['action'] ?? 'submit');
    if ($action === 'submit') {
        $result = runtime_issue_submit($pdo, (int)$me['id'], $body);
        json_out(['ok' => true] + $result);
    }
    if ($action === 'audit_observe') {
        json_out(['ok' => true] + runtime_audit_record_browser_checks($pdo, (int)$me['id'], $body));
    }
    if ($action === 'audit_run_start') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-issues');
        json_out(['ok' => true] + runtime_audit_start($pdo, (int)$me['id'], $body));
    }
    if ($action === 'audit_run_record_checks') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-issues');
        json_out(['ok' => true] + runtime_audit_record_checks($pdo, (string)($body['audit_run_id'] ?? ''), (int)$me['id'], is_array($body['checks'] ?? null) ? $body['checks'] : []));
    }
    if ($action === 'audit_run_finish') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-issues');
        json_out(['ok' => true] + runtime_audit_finish($pdo, (int)$me['id'], (string)($body['audit_run_id'] ?? ''), $body));
    }
    if ($action === 'audit_run_export') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'export-runtime-issues');
        json_out(['ok' => true] + runtime_audit_export($pdo, (int)$me['id'], (string)($body['audit_run_id'] ?? ''), (string)($body['request_id'] ?? ''), (string)($body['preview_token'] ?? '')));
    }
    if ($action === 'screenshot') {
        security_authorize_outside_content_or_json($pdo, $me, 'diagnostic_screenshot', ['source' => 'runtime_issue']);
        $result = runtime_issue_store_screenshot($pdo, (int)($body['issue_id'] ?? 0), (int)($body['occurrence_id'] ?? 0), (int)$me['id'], (string)($body['data_url'] ?? ''));
        json_out(['ok' => true, 'screenshot' => $result]);
    }
    if ($action === 'delete_screenshot') {
        $record = runtime_issue_screenshot_record($pdo, (string)($body['id'] ?? ''));
        if (!$record) json_out(['error' => 'Screenshot not found.'], 404);
        $managingEvidence = (int)$record['owner_user_id'] !== (int)$me['id'];
        if ($managingEvidence) {
            runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-evidence');
        }
        $deleted = runtime_issue_delete_screenshot(
            $pdo,
            (string)($body['id'] ?? ''),
            (int)$me['id'],
            $managingEvidence
        );
        if (!$deleted) json_out(['error' => 'Screenshot not found.'], 404);
        if ($managingEvidence) log_tool($pdo, (int)$me['id'], 'runtime_issue_screenshot_delete', null, null, 'Deleted censored diagnostic screenshot');
        json_out(['ok' => true]);
    }
    if ($action === 'update_status') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-issues');
        $issueId = (int)($body['issue_id'] ?? 0);
        $status = (string)($body['status'] ?? '');
        $detail = runtime_issue_update_status(
            $pdo,
            $issueId,
            $status,
            (int)$me['id'],
            (string)($body['reason'] ?? ''),
            (string)($body['verification_reference'] ?? ''),
            (int)($body['expected_revision'] ?? 0)
        );
        json_out(['ok' => true] + $detail);
    }
    if ($action === 'set_audit_baseline') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-issues');
        json_out(['ok' => true, 'auditBaseline' => runtime_issue_set_audit_baseline($pdo, (int)$me['id'])]);
    }
    if ($action === 'cleanup') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-evidence');
        security_require_recent_authentication_or_json();
        json_out(runtime_diagnostic_retention_run_cleanup($pdo, (int)$me['id'], !empty($body['confirmed'])));
    }
    if ($action === 'set_retention_hold') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-evidence');
        security_require_recent_authentication_or_json();
        json_out([
            'ok' => true,
            'retention' => runtime_diagnostic_retention_set_hold(
                $pdo,
                (int)($body['issue_id'] ?? 0),
                !empty($body['active']),
                (string)($body['reason'] ?? ''),
                (int)$me['id'],
                (int)($body['expected_revision'] ?? 0)
            ),
        ]);
    }
    if ($action === 'export') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'export-runtime-issues');
        json_out(['ok' => true] + runtime_issue_export(
            $pdo,
            (int)($body['issue_id'] ?? 0),
            (int)$me['id'],
            (string)($body['kind'] ?? 'support-bundle'),
            (string)($body['request_id'] ?? ''),
            (string)($body['preview_token'] ?? '')
        ));
    }
    if ($action === 'export_collection') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'export-runtime-issues');
        json_out(['ok' => true] + runtime_issue_collection_export(
            $pdo,
            (int)$me['id'],
            isset($body['status']) ? (string)$body['status'] : null,
            isset($body['severity']) ? (string)$body['severity'] : null,
            !empty($body['since_baseline']),
            (string)($body['request_id'] ?? ''),
            (string)($body['preview_token'] ?? '')
        ));
    }
    if ($action === 'delete_evidence') {
        runtime_issue_require_capability($pdo, (int)$me['id'], 'manage-runtime-evidence');
        security_require_recent_authentication_or_json();
        json_out(runtime_issue_delete_evidence(
            $pdo,
            (int)($body['issue_id'] ?? 0),
            (int)$me['id'],
            (int)($body['expected_revision'] ?? 0),
            (string)($body['fingerprint'] ?? ''),
            (string)($body['request_id'] ?? ''),
            (string)($body['confirmation_id'] ?? ''),
            !empty($body['confirmed'])
        ));
    }
} catch (Throwable $error) {
    runtime_issue_api_error($error);
}
json_out(['error' => 'Unknown action'], 400);
