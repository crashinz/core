<?php
declare(strict_types=1);

$p2pRequestStartedAt = hrtime(true);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('p2p-transfer', 'P2P_TRANSFER_FAILED', 'Direct transfer is temporarily unavailable.');
define('CHATSPACE_SQLITE_POLL_REQUEST', ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET');
require_once __DIR__ . '/../includes/base.php';

$p2pBootstrapFinishedAt = hrtime(true);
$pdo = db();
$p2pDatabaseFinishedAt = hrtime(true);
$p2pFailureStage = 'request';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $p2pFailureStage = 'authentication';
        $me = require_user();
        session_write_close();
        header('Cache-Control: no-store');
        $p2pAuthFinishedAt = hrtime(true);
        $p2pFailureStage = 'policy';
        $policy = p2p_transfer_policy($pdo);
        $p2pPolicyFinishedAt = hrtime(true);
        $p2pFailureStage = 'offer-poll';
        $offers = db_with_sqlite_poll_retry(
            $pdo,
            static fn(): array => p2p_transfer_poll_account($pdo, $me),
            'direct transfer offer poll'
        );
        $p2pOffersFinishedAt = hrtime(true);
        $p2pFailureStage = 'signal-poll';
        $signals = db_with_sqlite_poll_retry(
            $pdo,
            static fn(): array => p2p_transfer_signal_poll($pdo, $me),
            'direct transfer signal poll'
        );
        $p2pSignalsFinishedAt = hrtime(true);
        // Duration-only diagnostics; never expose transfer data or identities.
        $p2pDurations = [
            'cc_p2p_bootstrap' => $p2pBootstrapFinishedAt - $p2pRequestStartedAt,
            'cc_p2p_db' => $p2pDatabaseFinishedAt - $p2pBootstrapFinishedAt,
            'cc_p2p_auth' => $p2pAuthFinishedAt - $p2pDatabaseFinishedAt,
            'cc_p2p_policy' => $p2pPolicyFinishedAt - $p2pAuthFinishedAt,
            'cc_p2p_offers' => $p2pOffersFinishedAt - $p2pPolicyFinishedAt,
            'cc_p2p_signals' => $p2pSignalsFinishedAt - $p2pOffersFinishedAt,
            'cc_p2p_total' => $p2pSignalsFinishedAt - $p2pRequestStartedAt,
        ];
        $p2pServerTiming = [];
        foreach ($p2pDurations as $name => $nanoseconds) {
            $p2pServerTiming[] = $name . ';dur=' . number_format($nanoseconds / 1000000, 3, '.', '');
        }
        header('Server-Timing: ' . implode(', ', $p2pServerTiming));
        json_out([
            'ok' => true,
            'policy' => $policy,
            'offers' => $offers,
            'signals' => $signals,
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'GET or POST required'], 405);
    csrf_protect_post();
    $p2pFailureStage = 'mutation';
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
        $offer = db_with_sqlite_lock_retry(
            $pdo,
            static fn(): array => p2p_transfer_create_offer($pdo, $participant, $sessionId, $body),
            'direct transfer offer creation'
        );
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'offer' => $offer]);
    }
    if ($action === 'signal-ack') {
        $acknowledged = db_with_sqlite_lock_retry(
            $pdo,
            static fn(): bool => p2p_transfer_signal_acknowledge($pdo, $me, (int)($body['signal_id'] ?? 0)),
            'direct transfer signal acknowledgement'
        );
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'acknowledged' => $acknowledged]);
    }
    $offerId = trim((string)($body['offer_id'] ?? ''));
    if (!preg_match('/^pt_[a-f0-9]{32}$/', $offerId)) throw new P2PTransferException('The transfer offer identity is invalid.', 'P2P_TRANSFER_ID_INVALID', 400);
    if ($action === 'signal') {
        $type = trim((string)($body['signal_type'] ?? ''));
        $result = db_with_sqlite_lock_retry(
            $pdo,
            static fn(): array => p2p_transfer_signal_create($pdo, $me, $offerId, $type, $body),
            'direct transfer signal creation'
        );
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'signal' => $result]);
    }
    $result = db_with_sqlite_lock_retry(
        $pdo,
        static fn(): array => p2p_transfer_update($pdo, $me, $offerId, $action, $body),
        'direct transfer state update'
    );
    header('Cache-Control: no-store');
    json_out(['ok' => true, 'offer' => $result]);
} catch (P2PTransferException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode, 'facts' => $error->facts], $error->httpStatus);
} catch (Throwable $error) {
    $retryable = db_is_transient_lock_error($error);
    $failureCode = $retryable ? 'P2P_TRANSFER_DATABASE_BUSY' : 'P2P_TRANSFER_FAILED';
    $publicMessage = $retryable
        ? 'Direct transfer is briefly busy. Please try again.'
        : 'Direct transfer is temporarily unavailable.';
    $requestId = bin2hex(random_bytes(16));
    header('X-Request-ID: ' . $requestId);
    if ($retryable) header('Retry-After: 1');
    try {
        api_exception_record($error, 'p2p-transfer', $failureCode, $publicMessage,
            ['stage' => $p2pFailureStage], $requestId, $pdo, isset($me['id']) ? (int)$me['id'] : null);
    } catch (Throwable $recordError) {
        // Diagnostic storage failure must not replace the safe API response.
        error_log('Direct transfer diagnostic recording failed; request ' . $requestId);
    }
    json_out([
        'error' => $publicMessage,
        'code' => $failureCode,
        'request_id' => $requestId,
        'retryable' => $retryable,
        'retryAfterMs' => $retryable ? 500 : null,
    ], $retryable ? 503 : 500);
}
