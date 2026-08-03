<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_exception_handler.php';
api_install_exception_handler('server-media', 'SERVER_MEDIA_FAILED', 'Server media is temporarily unavailable.');
require_once __DIR__ . '/../includes/base.php';

$pdo = db();
try {
    server_media_expire($pdo);
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list'));
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($action, ['download','preview'], true)) {
        $me = require_user();
        $asset = server_media_asset_by_public_id($pdo, trim((string)($_GET['id'] ?? '')));
        $reviewId = trim((string)($_GET['review_session_id'] ?? ''));
        $adminReview = $reviewId !== '';
        if ($adminReview) {
            $review = server_media_require_review_session($pdo, $me, $reviewId);
            server_media_log_review_action($pdo, $me, $asset, $review, $action === 'preview' ? 'preview' : 'download');
        }
        if (!server_media_actor_can_access($pdo, $asset, $me, $adminReview)) throw new ServerMediaException('The file is unavailable.', 'SERVER_MEDIA_ACCESS_DENIED', 403);
        $path = (string)$asset['storage_path'];
        if (!is_file($path)) {
            $pdo->prepare("UPDATE server_media_assets SET status='missing',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$asset['id']]);
            throw new ServerMediaException('The stored file is missing.', 'SERVER_MEDIA_MISSING', 410);
        }
        if ($action === 'preview') {
            $classification = server_media_classify($path, (string)$asset['declared_mime'], (string)$asset['safe_name'], (string)$asset['category'] === 'voice-note');
            if ($classification['previewKind'] === 'text') {
                $text = file_get_contents($path, false, null, 0, 32768);
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Security-Policy: default-src \'none\'; sandbox');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store');
                echo is_string($text) ? $text : '';
                exit;
            }
            if (!in_array($classification['previewKind'], ['image','media'], true)) {
                json_out(['ok' => true, 'preview' => ['kind' => 'metadata', 'risk' => $asset['risk_class'], 'detail' => $asset['risk_detail'], 'malwareStatus' => 'Not scanned for malware']]);
            }
            if ($classification['previewKind'] === 'image') {
                $previewPath = is_string($asset['preview_path']) ? $asset['preview_path'] : '';
                if ($previewPath === '' || !is_file($previewPath)) {
                    json_out(['ok' => true, 'preview' => ['kind' => 'metadata', 'risk' => $asset['risk_class'], 'detail' => 'A sanitized thumbnail is unavailable. ' . $asset['risk_detail'], 'malwareStatus' => 'Not scanned for malware']]);
                }
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . (string)filesize($previewPath));
                header('X-Content-Type-Options: nosniff');
                header('Content-Security-Policy: default-src \'none\'; sandbox');
                header('Cache-Control: private, no-store');
                header('Content-Disposition: inline; filename="preview.jpg"');
                readfile($previewPath);
                exit;
            }
            if ($classification['previewKind'] === 'media' && (int)$asset['byte_size'] > 5 * 1024 * 1024) {
                json_out(['ok' => true, 'preview' => ['kind' => 'metadata', 'risk' => $asset['risk_class'], 'detail' => 'This media file exceeds the bounded 5 MB in-page preview limit. Use authenticated Download for deliberate review.', 'malwareStatus' => 'Not scanned for malware']]);
            }
        }
        server_media_record_delivery($pdo, $asset, (int)$me['id']);
        header('Content-Type: ' . (string)$asset['detected_mime']);
        header('Content-Length: ' . (string)filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('Cache-Control: private, no-store');
        $disposition = $action === 'preview' ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes((string)$asset['safe_name'], '"\\') . '"');
        readfile($path);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $view = trim((string)($_GET['view'] ?? 'room'));
        if ($view === 'admin') {
            $actor = require_user();
            security_require_recent_authentication_or_json();
        } else {
            $sessionId = resolve_session_id($pdo, $_GET['session_id'] ?? '');
            $participant = auth_participant($pdo, $sessionId, (string)($_GET['join_token'] ?? ''));
            $actor = ['id' => (int)$participant['user_id'], 'role' => (string)($participant['role'] ?? 'user')];
        }
        $sessionId = isset($sessionId) ? $sessionId : (int)($_GET['session_id'] ?? 0);
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'policy' => server_media_policy($pdo), 'usage' => server_media_usage_summary($pdo), 'list' => server_media_list($pdo, $actor, $view, $sessionId, (int)($_GET['page'] ?? 1), (int)($_GET['page_size'] ?? 30), $_GET)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'GET or POST required'], 405);
    csrf_protect_post();
    $body = input_json();
    $actor = require_user();
    $action = trim((string)($body['action'] ?? ''));
    if ($action === 'remove-own') {
        json_out(['ok' => true, 'asset' => server_media_remove_own($pdo, $actor, trim((string)($body['id'] ?? '')))]);
    }
    if ($action === 'start-review') {
        json_out(['ok' => true, 'reviewSession' => server_media_start_review_session($pdo, $actor, (string)($body['reason'] ?? ''))]);
    }
    if ($action === 'bulk') {
        json_out(['ok' => true, 'result' => server_media_bulk_mutate(
            $pdo,
            $actor,
            (array)($body['ids'] ?? []),
            trim((string)($body['bulk_action'] ?? '')),
            trim((string)($body['review_session_id'] ?? '')),
            (string)($body['reason'] ?? '')
        )]);
    }
    $assetId = trim((string)($body['id'] ?? ''));
    $reviewId = trim((string)($body['review_session_id'] ?? ''));
    $result = server_media_mutate($pdo, $actor, $assetId, $action, $reviewId, (string)($body['reason'] ?? ''));
    json_out(['ok' => true, 'asset' => $result]);
} catch (ServerMediaException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode, 'facts' => $error->facts], $error->httpStatus);
}
