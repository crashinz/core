<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$actor = current_user();
if (!$actor) json_out(['error' => 'Authentication is required.'], 401);
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) json_out(['error' => 'Unsupported method'], 405);

$publicId = trim((string)($_GET['id'] ?? ''));
$generation = (int)($_GET['generation'] ?? 0);
$role = (string)($_GET['role'] ?? '');
$purpose = (string)($_GET['purpose'] ?? 'catalog');
$token = trim((string)($_GET['token'] ?? ''));
if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $publicId) || $generation < 1 || !in_array($role, ['animation', 'poster', 'audio'], true) || !in_array($purpose, ['catalog', 'message', 'editor', 'admin'], true)) {
    json_out(['error' => 'Gesture media request is invalid.'], 400);
}

try {
    $pdo = db();
    $record = gesture_package_media_record($pdo, $publicId, $generation);
    gesture_package_authorize_media($pdo, $actor, $record, $token, $role, $purpose);
    $storageName = (string)($record[$role . '_storage_name'] ?? '');
    $path = gesture_package_resolve_storage($storageName);
    if ($path === null) throw new GestureCatalogException('Gesture media is unavailable.', 404, 'GESTURE_MEDIA_UNAVAILABLE');
    $mime = (string)($record[$role . '_mime'] ?? 'application/octet-stream');
    $allowed = [
        'animation' => ['image/gif'],
        'poster' => ['image/gif', 'image/png', 'image/jpeg', 'image/webp'],
        'audio' => ['audio/mpeg'],
    ];
    if (!in_array($mime, $allowed[$role], true)) throw new GestureCatalogException('Gesture media type is unavailable.', 404, 'GESTURE_MEDIA_TYPE_INVALID');
    $size = filesize($path);
    if ($size === false || $size < 1 || $size > GESTURE_PACKAGE_MAX_ENTRY) throw new GestureCatalogException('Gesture media is unavailable.', 404, 'GESTURE_MEDIA_UNAVAILABLE');

    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match) || ($match[1] === '' && $match[2] === '')) {
            header('Content-Range: bytes */' . $size);
            json_out(['error' => 'Requested media range is invalid.'], 416);
        }
        if ($match[1] === '') {
            $length = min($size, max(1, (int)$match[2]));
            $start = $size - $length;
        } else {
            $start = (int)$match[1];
        }
        if ($match[2] !== '') $end = min($end, (int)$match[2]);
        if ($start < 0 || $start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            json_out(['error' => 'Requested media range is unavailable.'], 416);
        }
        $status = 206;
    }
    $length = $end - $start + 1;
    security_protect_private_response();
    http_response_code($status);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    if ($status === 206) header("Content-Range: bytes {$start}-{$end}/{$size}");
    $extension = match ($mime) { 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'audio/mpeg' => 'mp3', default => 'gif' };
    header('Content-Disposition: inline; filename="gesture-' . $role . '.' . $extension . '"');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new GestureCatalogException('Gesture media is unavailable.', 404, 'GESTURE_MEDIA_UNAVAILABLE');
    try {
        fseek($handle, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(65536, $remaining));
            if (!is_string($chunk) || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    } finally {
        fclose($handle);
    }
    exit;
} catch (GestureCatalogException $error) {
    json_out(gesture_catalog_exception_payload($error), $error->httpStatus);
} catch (Throwable) {
    json_out(['error' => 'Gesture media could not be delivered.', 'error_code' => 'GESTURE_MEDIA_DELIVERY_FAILED'], 500);
}
