<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$me = require_staff(['admin']);
$pdo = db();

try {
    network_privacy_require_owner($pdo, (int)$me['id']);
    security_require_recent_authentication_or_json();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_out([
            'transportPolicy' => network_privacy_policy_projection($pdo, (int)$me['id']),
            'manualBanPolicy' => network_moderation_policy_projection($pdo, (int)$me['id']),
            'contexts' => network_moderation_contexts_projection($pdo, (int)$me['id']),
            'bans' => network_moderation_bans_projection($pdo, (int)$me['id']),
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['error' => 'Unsupported method'], 405);
    }
    $body = input_json();
    $action = (string)($body['action'] ?? '');
    if ($action === 'update_transport_policy') {
        json_out([
            'ok' => true,
            'transportPolicy' => network_privacy_update_policy($pdo, (int)$me['id'], $body),
        ]);
    }
    if ($action === 'preview_manual_ban') {
        json_out([
            'ok' => true,
            'preview' => network_moderation_preview_ban(
                $pdo,
                (int)$me['id'],
                (string)($body['contextId'] ?? ''),
                (string)($body['reason'] ?? ''),
                $body['durationMinutes'] ?? null,
                !empty($body['permanent'])
            ),
        ]);
    }
    if ($action === 'apply_manual_ban') {
        json_out([
            'ok' => true,
            'application' => network_moderation_apply_ban(
                $pdo,
                (int)$me['id'],
                (string)($body['previewId'] ?? ''),
                (string)($body['impactSha256'] ?? ''),
                (string)($body['requestId'] ?? ''),
                !empty($body['confirmed'])
            ),
        ]);
    }
    if ($action === 'remove_manual_ban') {
        json_out([
            'ok' => true,
            'removal' => network_moderation_remove_ban(
                $pdo,
                (int)$me['id'],
                (string)($body['banId'] ?? ''),
                (string)($body['reason'] ?? ''),
                (string)($body['requestId'] ?? ''),
                !empty($body['confirmed'])
            ),
        ]);
    }
    json_out(['error' => 'Unknown action'], 400);
} catch (NetworkPrivacyException $error) {
    json_out([
        'error' => $error->getMessage(),
        'code' => $error->errorCode,
    ] + $error->projection, $error->httpStatus);
}
