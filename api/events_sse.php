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

const SSE_MAX_BATCHES_PER_CONNECTION = 6;
const SSE_RENEWAL_AFTER_SECONDS = 14;

function sse_emit(string $event, array $payload, int $eventId = 0): void
{
    if ($eventId > 0) echo 'id: ' . $eventId . "\n";
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    ) . "\n\n";
    if (function_exists('ob_flush')) @ob_flush();
    flush();
}

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

try {
    // Authenticate before committing to SSE framing so initial authorization
    // failures retain the ordinary JSON/API error contract.
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

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');
header('X-ChatSpace-SSE-Model: bounded-multi-batch');

$request = $_GET;
$roomCursor = max(0, (int)($request['last_event_id'] ?? 0));
$communityCursor = max(0, (int)($request['last_community_event_id'] ?? 0));
$startedAt = microtime(true);

for ($index = 0; $index < SSE_MAX_BATCHES_PER_CONNECTION; $index++) {
    $roomCursor = max($roomCursor, (int)($batch['cursor']['room'] ?? 0));
    $communityCursor = max($communityCursor, (int)($batch['cursor']['community'] ?? 0));
    $batch['cursor']['room'] = $roomCursor;
    $batch['cursor']['community'] = $communityCursor;
    sse_emit('batch', $batch, max($roomCursor, $communityCursor));

    // A transport-only comment keeps intermediaries honest without creating
    // an application event or advancing either cursor.
    echo ': keepalive ' . ($index + 1) . "\n\n";
    if (function_exists('ob_flush')) @ob_flush();
    flush();

    if ($index + 1 >= SSE_MAX_BATCHES_PER_CONNECTION
        || microtime(true) - $startedAt >= SSE_RENEWAL_AFTER_SECONDS
        || connection_aborted()) {
        break;
    }

    $request['last_event_id'] = (string)$roomCursor;
    $request['last_community_event_id'] = (string)$communityCursor;
    try {
        $batch = event_delivery_collect($pdo, $request);
    } catch (EventDeliveryAuthorizationException $error) {
        sse_emit('authorization', [
            'error' => $error->getMessage(),
            'code' => $error->errorCode,
        ]);
        return;
    }
}

if (!connection_aborted()) {
    sse_emit('renew', ['reason' => 'expected-bounded-renewal']);
}
