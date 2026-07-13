<?php
require_once __DIR__ . '/../includes/base.php';

$pdo = db();

function avatar_relationship_api_out(array $result): never {
    $status = max(200, (int)($result['http_status'] ?? 200));
    unset($result['http_status']);
    json_out($result, $status);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sessionId = resolve_session_id($pdo, $_GET['session_id'] ?? '');
    $participant = auth_participant($pdo, $sessionId, (string)($_GET['join_token'] ?? ''));
    $participantId = (int)$participant['id'];
    avatar_relationship_api_out([
        'ok' => true,
        'relationship' => avatar_relationship_active_for_participant(
            $pdo,
            $sessionId,
            $participantId,
            $participantId
        ),
        'requests' => avatar_relationship_requests_for_actor($pdo, $sessionId, $participantId),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST required'], 405);
}

$body = input_json();
$sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
$participant = auth_participant($pdo, $sessionId, (string)($body['join_token'] ?? ''));
$participantId = (int)$participant['id'];
$action = (string)($body['action'] ?? '');
$expectedVersion = (int)($body['expected_version'] ?? 0);

if ($expectedVersion <= 0) {
    avatar_relationship_api_out(avatar_relationship_operation_error(
        'RELATIONSHIP_VERSION_STALE',
        'A current relationship version is required.',
        'relationship-version-required',
        400
    ));
}

if ($action === 'request_join') {
    avatar_relationship_api_out(avatar_relationship_create_request(
        $pdo,
        $sessionId,
        $participantId,
        trim((string)($body['relationship_id'] ?? '')),
        $expectedVersion,
        'join-request',
        $participantId,
        (string)($body['relationship_role'] ?? 'normal'),
        isset($body['lap_host_participant_id']) ? (int)$body['lap_host_participant_id'] : null
    ));
}

if ($action === 'invite') {
    avatar_relationship_api_out(avatar_relationship_create_request(
        $pdo,
        $sessionId,
        $participantId,
        trim((string)($body['relationship_id'] ?? '')),
        $expectedVersion,
        'invitation',
        (int)($body['target_participant_id'] ?? 0),
        (string)($body['relationship_role'] ?? 'normal'),
        isset($body['lap_host_participant_id']) ? (int)$body['lap_host_participant_id'] : null
    ));
}

if (in_array($action, ['accept_request', 'reject_request', 'cancel_request'], true)) {
    $resolution = [
        'accept_request' => 'accept',
        'reject_request' => 'reject',
        'cancel_request' => 'cancel',
    ][$action];
    avatar_relationship_api_out(avatar_relationship_resolve_request(
        $pdo,
        $sessionId,
        $participantId,
        trim((string)($body['request_id'] ?? '')),
        $expectedVersion,
        $resolution
    ));
}

if ($action === 'set_join_policy') {
    avatar_relationship_api_out(avatar_relationship_set_join_policy(
        $pdo,
        $sessionId,
        $participantId,
        trim((string)($body['relationship_id'] ?? '')),
        $expectedVersion,
        (string)($body['join_policy'] ?? '')
    ));
}

json_out(['error' => 'Unknown action'], 400);
