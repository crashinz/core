<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('p2p-transfer', 'P2P_TRANSFER_FAILED', 'Direct transfer is temporarily unavailable.');
require_once __DIR__ . '/../includes/base.php';

$pdo = db();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $me = require_user();
        header('Cache-Control: no-store');
        json_out([
            'ok' => true,
            'policy' => p2p_transfer_policy($pdo),
            'offers' => p2p_transfer_poll_account($pdo, $me),
            'signals' => p2p_transfer_signal_poll($pdo, $me),
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'GET or POST required'], 405);
    csrf_protect_post();
    $body = input_json();
    $action = trim((string)($body['action'] ?? 'offer'));

    if ($action === 'review-upstream') {
        $me = require_user();
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'provenance' => p2p_transfer_record_source_check($pdo, $me)]);
    }

    $me = require_user();
    if ($action === 'offer') {
        $sessionId = resolve_session_id($pdo, $body['session_id'] ?? '');
        $participant = auth_participant($pdo, $sessionId, (string)($body['join_token'] ?? ''));
        if ((int)$participant['user_id'] !== (int)$me['id']) throw new P2PTransferException('The participant identity is invalid.', 'P2P_TRANSFER_ACCESS_DENIED', 403);
        $offer = p2p_transfer_create_offer($pdo, $participant, $sessionId, $body);
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'offer' => $offer]);
    }
    if ($action === 'signal-ack') {
        $acknowledged = p2p_transfer_signal_acknowledge($pdo, $me, (int)($body['signal_id'] ?? 0));
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'acknowledged' => $acknowledged]);
    }
    $offerId = trim((string)($body['offer_id'] ?? ''));
    if (!preg_match('/^pt_[a-f0-9]{32}$/', $offerId)) throw new P2PTransferException('The transfer offer identity is invalid.', 'P2P_TRANSFER_ID_INVALID', 400);
    if ($action === 'signal') {
        $type = trim((string)($body['signal_type'] ?? ''));
        $result = p2p_transfer_signal_create($pdo, $me, $offerId, $type, $body);
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'signal' => $result]);
    }
    $result = p2p_transfer_update($pdo, $me, $offerId, $action, $body);
    header('Cache-Control: no-store');
    json_out(['ok' => true, 'offer' => $result]);
} catch (P2PTransferException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode, 'facts' => $error->facts], $error->httpStatus);
}
