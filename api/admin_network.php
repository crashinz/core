<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$me = require_staff(['admin']);
$pdo = db();

try {
    network_privacy_require_owner($pdo, (int)$me['id']);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $currentOpaque = network_privacy_observe($pdo, network_privacy_client_ip());
        json_out([
            'policy' => network_privacy_policy_projection($pdo, (int)$me['id']),
            'currentNetworkIdentifier' => $currentOpaque,
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['error' => 'Unsupported method'], 405);
    }
    security_require_recent_authentication_or_json();
    $body = input_json();
    $action = (string)($body['action'] ?? '');
    if ($action === 'update_policy') {
        json_out([
            'ok' => true,
            'policy' => network_privacy_update_policy($pdo, (int)$me['id'], $body),
        ]);
    }
    if ($action === 'reveal') {
        json_out([
            'ok' => true,
            'reveal' => network_privacy_reveal(
                $pdo,
                (int)$me['id'],
                trim((string)($body['opaqueId'] ?? '')),
                (string)($body['reason'] ?? ''),
                (int)($body['durationMinutes'] ?? NETWORK_PRIVACY_DEFAULT_REVEAL_MINUTES)
            ),
        ]);
    }
    if ($action === 'hide') {
        json_out(['ok' => true] + network_privacy_hide($pdo, (int)$me['id']));
    }
    json_out(['error' => 'Unknown action'], 400);
} catch (NetworkPrivacyException $error) {
    json_out([
        'error' => $error->getMessage(),
        'code' => $error->errorCode,
    ] + $error->projection, $error->httpStatus);
}
