<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('heartbeat', 'HEARTBEAT_FAILED', 'Room presence is temporarily unavailable.');
define('CHATSPACE_SQLITE_POLL_REQUEST', ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET');
require_once __DIR__ . '/../includes/base.php';

header('Cache-Control: no-cache, no-store, must-revalidate');

$pdo = db();
$sessionId = resolve_session_id($pdo, $_GET['session_id'] ?? '');
$participant = auth_participant($pdo, $sessionId, $_GET['join_token'] ?? '');
session_write_close();

$mode = (string)($_GET['mode'] ?? 'all');
$presenceWriteDeferred = false;
if ($mode === 'presence' || $mode === 'all') {
    try {
        touch_participant_presence($pdo, $participant);
    } catch (Throwable $error) {
        if (!db_is_transient_lock_error($error)) throw $error;
        // One coalesced timestamp refresh may be deferred under brief SQLite
        // contention. The next heartbeat retries it; current presence remains
        // readable instead of turning routine contention into an HTTP 500.
        $presenceWriteDeferred = true;
    }
    runtime_maintenance_for_session($pdo, $sessionId);
}

function heartbeat_presence(PDO $pdo, int $sessionId): array {
    $stmt = $pdo->prepare('SELECT id, webcam_path, webcam_enabled, last_seen_at FROM participants WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    return array_map(fn($p) => [
        'id' => (int)$p['id'],
        'webcam_path' => $p['webcam_path'],
        'webcam_enabled' => !empty($p['webcam_enabled']),
        'online' => participant_presence_is_online($p['last_seen_at']),
    ], $stmt->fetchAll());
}

$response = [
    'ok' => true,
    'server_time' => gmdate('c'),
];
if ($presenceWriteDeferred) $response['presence_write_deferred'] = true;

if ($mode === 'presence' || $mode === 'all') {
    $response['participants'] = heartbeat_presence($pdo, $sessionId);
}

json_out($response);
