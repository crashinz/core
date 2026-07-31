<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler(
    'room-poll',
    'ROOM_POLL_FAILED',
    'Room events are temporarily unavailable.'
);
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/event_delivery.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
try {
    json_out(event_delivery_collect(db(), $_GET));
} catch (EventDeliveryAuthorizationException $error) {
    json_out([
        'error' => $error->getMessage(),
        'code' => $error->errorCode,
        $error->errorCode === 'POLICY_REACCEPTANCE_REQUIRED'
            ? 'policyUrl'
            : 'accountUrl' => $error->actionUrl,
    ], $error->httpStatus);
}
