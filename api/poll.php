<?php
declare(strict_types=1);

$pollRequestStartedAt = hrtime(true);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler(
    'room-poll',
    'ROOM_POLL_FAILED',
    'Room events are temporarily unavailable.'
);
define('CHATSPACE_SQLITE_POLL_REQUEST', ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET');
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/event_delivery.php';

$pollBootstrapFinishedAt = hrtime(true);
session_write_close();

header('Cache-Control: no-cache, no-store, must-revalidate');
try {
    $fixtureShortPoll = PHP_SAPI === 'cli-server'
        || trim((string)getenv('CORECHAT_ALL_CLASSIC_RUN_ROOT')) !== ''
        || (defined('CHATSPACE_RUNTIME_VERIFICATION_CONTROLS_ENABLED')
            && CHATSPACE_RUNTIME_VERIFICATION_CONTROLS_ENABLED);
    $pollDatabaseStartedAt = hrtime(true);
    $pollDatabase = db();
    $pollDatabaseFinishedAt = hrtime(true);
    $pollPayload = event_delivery_collect(
        $pollDatabase,
        $_GET,
        $fixtureShortPoll ? 1 : EVENT_DELIVERY_POLL_ATTEMPTS,
        $fixtureShortPoll ? 0 : EVENT_DELIVERY_POLL_SLEEP_MICROSECONDS
    );
    $pollCollectionFinishedAt = hrtime(true);
    // Emit durations only after the normal participant authorization succeeds.
    // No event payloads, identifiers, query values, or credentials are included.
    $pollDurations = [
        'cc_poll_bootstrap' => $pollBootstrapFinishedAt - $pollRequestStartedAt,
        'cc_poll_db' => $pollDatabaseFinishedAt - $pollDatabaseStartedAt,
        'cc_poll_collect' => $pollCollectionFinishedAt - $pollDatabaseFinishedAt,
        'cc_poll_total' => $pollCollectionFinishedAt - $pollRequestStartedAt,
    ];
    $pollServerTiming = [];
    foreach ($pollDurations as $name => $nanoseconds) {
        $pollServerTiming[] = $name . ';dur=' . number_format($nanoseconds / 1000000, 3, '.', '');
    }
    header('Server-Timing: ' . implode(', ', $pollServerTiming));
    json_out($pollPayload);
} catch (EventDeliveryAuthorizationException $error) {
    json_out([
        'error' => $error->getMessage(),
        'code' => $error->errorCode,
        $error->errorCode === 'POLICY_REACCEPTANCE_REQUIRED'
            ? 'policyUrl'
            : 'accountUrl' => $error->actionUrl,
    ], $error->httpStatus);
}
