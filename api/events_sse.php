<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler(
    'room-sse',
    'ROOM_SSE_FAILED',
    'Realtime room events are temporarily unavailable.'
);
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/event_delivery.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_out(['error' => 'Method not allowed'], 405);
}

$pdo = db();
$policy = transport_policy_projection($pdo);
if (($policy['selectedAdapter'] ?? 'polling') !== 'sse'
    || empty($policy['adapters']['sse']['eligible'])) {
    json_out([
        'error' => 'Server-Sent Events are unavailable for this installation.',
        'code' => 'SSE_CAPABILITY_UNAVAILABLE',
        'fallbackAdapter' => 'polling',
        'reason' => (string)$policy['fallbackReason'],
    ], 409);
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');

try {
    $batch = event_delivery_collect($pdo, $_GET);
} catch (EventDeliveryAuthorizationException $error) {
    json_out([
        'error' => $error->getMessage(),
        'code' => $error->errorCode,
        $error->errorCode === 'POLICY_REACCEPTANCE_REQUIRED'
            ? 'policyUrl'
            : 'accountUrl' => $error->actionUrl,
        'fallbackAdapter' => 'polling',
    ], $error->httpStatus);
}
$encoded = json_encode(
    $batch,
    JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
);
$eventId = max(
    (int)($batch['cursor']['room'] ?? 0),
    (int)($batch['cursor']['community'] ?? 0)
);
echo 'id: ' . $eventId . "\n";
echo "event: batch\n";
echo 'data: ' . $encoded . "\n\n";
if (function_exists('ob_flush')) @ob_flush();
flush();
