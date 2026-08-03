<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('p2p-transfer-evidence', 'P2P_TRANSFER_EVIDENCE_FAILED', 'Transfer evidence could not be submitted.');
require_once __DIR__ . '/../includes/base.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
csrf_protect_post();

$pdo = db();
$storedId = '';
try {
    $sessionId = resolve_session_id($pdo, $_POST['session_id'] ?? '');
    $participant = auth_participant($pdo, $sessionId, (string)($_POST['join_token'] ?? ''));
    $offerId = trim((string)($_POST['offer_id'] ?? ''));
    $reason = trim(preg_replace('/\s+/u', ' ', (string)($_POST['reason'] ?? '')) ?? '');
    if (!preg_match('/^pt_[a-f0-9]{32}$/', $offerId)) {
        throw new P2PTransferException('The transfer offer identity is invalid.', 'P2P_TRANSFER_ID_INVALID', 400);
    }
    if (strlen($reason) < 8 || strlen($reason) > 2000) {
        throw new P2PTransferException('Enter a specific report reason.', 'P2P_TRANSFER_REPORT_REASON_REQUIRED', 422);
    }
    $offer = p2p_transfer_offer_by_public_id($pdo, $offerId);
    if ((int)$offer['session_id'] !== $sessionId
        || (int)$offer['recipient_participant_id'] !== (int)$participant['id']
        || (int)$offer['recipient_user_id'] !== (int)$participant['user_id']
        || (string)$offer['status'] !== 'completed') {
        throw new P2PTransferException('Only the recipient may submit a completed received file.', 'P2P_TRANSFER_EVIDENCE_DENIED', 403);
    }
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new P2PTransferException('Choose the received file to submit with this report.', 'P2P_TRANSFER_EVIDENCE_FILE_REQUIRED', 400);
    }
    $fileCount = (int)($offer['file_count'] ?? 1);
    $maximumEvidenceBytes = (int)p2p_transfer_policy($pdo)['maxFileBytes'] * min(max(1, $fileCount), 10) + 4 * 1024 * 1024;
    if ($fileCount === 1 && (int)($file['size'] ?? 0) !== (int)$offer['byte_size']) {
        throw new P2PTransferException('The submitted file size does not match the received transfer.', 'P2P_TRANSFER_EVIDENCE_MISMATCH', 409);
    }
    if ($fileCount > 1 && ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maximumEvidenceBytes)) {
        throw new P2PTransferException('The submitted batch archive is outside the accepted transfer boundary.', 'P2P_TRANSFER_EVIDENCE_MISMATCH', 409);
    }
    $file['name'] = $fileCount > 1 ? 'CoreChat-transfer-' . substr($offerId, -8) . '.zip' : (string)$offer['safe_name'];
    $file['type'] = $fileCount > 1 ? 'application/zip' : (string)$offer['declared_mime'];
    $asset = server_media_upload(
        $pdo,
        $file,
        $participant,
        $sessionId,
        'p2p-evidence',
        false,
        [],
        ['category' => 'moderation-evidence', 'maxBytes' => $maximumEvidenceBytes]
    );
    $storedId = (string)$asset['id'];
    if ($fileCount > 1) {
        if (!class_exists('ZipArchive')) {
            throw new P2PTransferException('The received batch archive cannot be verified on this installation.', 'P2P_TRANSFER_EVIDENCE_ARCHIVE_UNAVAILABLE', 409);
        }
        $stored = server_media_asset_by_public_id($pdo, $storedId);
        $manifest = json_decode((string)($offer['manifest_json'] ?? ''), true);
        $manifestFiles = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $expected = [];
        foreach ($manifestFiles as $manifestFile) {
            $expected[(string)($manifestFile['safeName'] ?? '')] = (int)($manifestFile['size'] ?? -1);
        }
        $zip = new ZipArchive();
        $opened = $zip->open((string)$stored['storage_path'], ZipArchive::RDONLY);
        $seen = [];
        $valid = $opened === true && $zip->numFiles >= 1 && $zip->numFiles <= $fileCount;
        if ($valid) {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                $entryName = is_array($stat) ? str_replace('\\', '/', (string)($stat['name'] ?? '')) : '';
                $entrySize = is_array($stat) ? (int)($stat['size'] ?? -1) : -1;
                $valid = $entryName !== '' && isset($expected[$entryName]) && $expected[$entryName] === $entrySize
                    && !isset($seen[$entryName]) && empty($stat['encryption_method']) && (int)($stat['comp_method'] ?? -1) === ZipArchive::CM_STORE;
                if (!$valid) break;
                $seen[$entryName] = true;
            }
        }
        if ($opened === true) $zip->close();
        if (!$valid || !$seen) {
            throw new P2PTransferException('The submitted batch archive does not match the accepted transfer manifest.', 'P2P_TRANSFER_EVIDENCE_MISMATCH', 409);
        }
    }

    if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
    else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
    $report = moderation_safety_submit_report($pdo, (int)$participant['user_id'], [
        'origin_type' => 'file',
        'origin_reference' => $offerId,
        'reported_user_id' => (int)$offer['sender_user_id'],
        'reason' => $reason,
        'evidence_type' => 'file-offer',
        'evidence' => [
            'transferId' => $offerId,
            'safeName' => (string)$offer['safe_name'],
            'sizeBytes' => (int)$offer['byte_size'],
            'declaredMime' => (string)$offer['declared_mime'],
            'detectedType' => (string)$offer['detected_type'],
            'deliveryMethod' => (string)$offer['requested_delivery'],
            'finalConnection' => $offer['final_connection'],
            'payloadSubmitted' => true,
            'protectedServerMediaId' => $storedId,
        ],
    ]);
    p2p_transfer_event($pdo, $offer, (int)$participant['user_id'], 'evidence-submitted', 'Recipient voluntarily submitted the received file as protected moderation evidence.');
    $pdo->commit();
    log_tool($pdo, (int)$participant['user_id'], 'p2p_transfer_evidence_submitted', (int)$offer['sender_user_id'], null, 'Transfer ' . $offerId . '; protected file ' . $storedId . '; report ' . $report['reference'] . '.');
    header('Cache-Control: no-store');
    json_out(['ok' => true, 'reportReference' => $report['reference'], 'payloadSubmitted' => true]);
} catch (P2PTransferException|ModerationSafetyException|ServerMediaException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($storedId !== '') {
        try { server_media_discard_unreferenced($pdo, $storedId); } catch (Throwable) {}
    }
    $code = property_exists($error, 'errorCode') ? $error->errorCode : 'P2P_TRANSFER_EVIDENCE_FAILED';
    $status = property_exists($error, 'httpStatus') ? $error->httpStatus : 409;
    json_out(['error' => $error->getMessage(), 'code' => $code], $status);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($storedId !== '') {
        try { server_media_discard_unreferenced($pdo, $storedId); } catch (Throwable) {}
    }
    throw $error;
}
