<?php
declare(strict_types=1);

/**
 * Build 000049 authoritative room-background upload owner.
 *
 * Room creation and edit adapters retain authorization and presentation. This
 * owner preserves validation, limits, storage, supplied-thumbnail, optional
 * ffmpeg-thumbnail, result, and failure behavior.
 */

function save_room_background_upload(array $upload, ?array $thumbUpload = null): array {
    if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        return ['path' => null, 'mime' => null, 'thumb_path' => null];
    }
    $pdo = db();
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($upload['tmp_name']) ?: '';
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm'];
    if (!in_array($mime, $allowed, true) || !security_valid_uploaded_file_signature((string)$upload['tmp_name'], $mime)) {
        throw new RuntimeException('Unsupported background type');
    }
    $isVideo = str_starts_with($mime, 'video/');
    $maxBytes = $isVideo ? app_setting_bytes($pdo, 'room_video_max_size_mb', 200) : app_setting_bytes($pdo, 'room_image_max_size_mb', 10);
    if ((int)($upload['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Background file is too large');
    }
    $ext = match ($mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        default => 'jpg',
    };
    $dir = __DIR__ . '/../assets/uploads/backgrounds';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $base = bin2hex(random_bytes(12));
    $file = $base . '.' . $ext;
    $dest = $dir . '/' . $file;
    if (!move_uploaded_file($upload['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save background');
    }
    $path = '/assets/uploads/backgrounds/' . $file;
    security_assert_storage_destination('room_background_upload', $path);
    $thumbPath = null;
    if ($isVideo && $thumbUpload && !empty($thumbUpload['tmp_name']) && is_uploaded_file($thumbUpload['tmp_name'])) {
        $thumbInfo = new finfo(FILEINFO_MIME_TYPE);
        $thumbMime = $thumbInfo->file($thumbUpload['tmp_name']) ?: '';
        if (in_array($thumbMime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && security_valid_image_file((string)$thumbUpload['tmp_name'], $thumbMime)
            && (int)($thumbUpload['size'] ?? 0) <= 2 * 1024 * 1024) {
            $thumbExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$thumbMime];
            $thumbFile = $base . '-thumb.' . $thumbExt;
            $thumbDest = $dir . '/' . $thumbFile;
            if (move_uploaded_file($thumbUpload['tmp_name'], $thumbDest)) {
                $thumbPath = '/assets/uploads/backgrounds/' . $thumbFile;
            }
        }
    }
    if ($isVideo && !$thumbPath && function_exists('shell_exec')) {
        $thumbFile = $base . '-thumb.jpg';
        $thumbDest = $dir . '/' . $thumbFile;
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($dest) . ' -ss 00:00:01 -frames:v 1 -vf ' . escapeshellarg('scale=720:-1') . ' ' . escapeshellarg($thumbDest) . ' 2>/dev/null';
        @shell_exec($cmd);
        if (is_file($thumbDest) && filesize($thumbDest) > 0) {
            $thumbPath = '/assets/uploads/backgrounds/' . $thumbFile;
        }
    }
    return ['path' => $path, 'mime' => $mime, 'thumb_path' => $thumbPath];
}
