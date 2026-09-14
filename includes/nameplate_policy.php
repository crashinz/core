<?php
declare(strict_types=1);

const NAMEPLATE_UPLOAD_MAX_WIDTH_PX = 1500;
const NAMEPLATE_UPLOAD_MAX_HEIGHT_PX = 300;

function nameplate_dimension_policy(): array
{
    return [
        'maxWidth' => NAMEPLATE_UPLOAD_MAX_WIDTH_PX,
        'maxHeight' => NAMEPLATE_UPLOAD_MAX_HEIGHT_PX,
    ];
}

function nameplate_upload_policy(PDO $pdo): array
{
    $mb = (float)app_setting($pdo, 'nameplate_max_size_mb', '5');
    if (!is_finite($mb) || $mb < 0.5 || $mb > 50) $mb = 5.0;
    return nameplate_dimension_policy() + [
        'minWidth' => 1, 'minHeight' => 1,
        'recommendedWidth' => 1500, 'recommendedHeight' => 300,
        'maxBytes' => (int)round($mb * 1024 * 1024), 'maxMegabytes' => $mb,
        'mimeTypes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    ];
}

function nameplate_upload_inspect(PDO $pdo, string $path): array
{
    $policy = nameplate_upload_policy($pdo);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    $types = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/gif' => IMAGETYPE_GIF, 'image/webp' => IMAGETYPE_WEBP];
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($path);
    if (!isset($types[$mime]) || !$dimensions || (int)$dimensions[2] !== $types[$mime]) {
        return ['error' => 'Choose a valid JPEG, PNG, GIF, or WebP nameplate image.'];
    }
    if (filesize($path) > $policy['maxBytes']) {
        return ['error' => 'Nameplate images must be ' . $policy['maxMegabytes'] . ' MB or smaller.'];
    }
    if ($dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > $policy['maxWidth'] || $dimensions[1] > $policy['maxHeight']) {
        return ['error' => 'Nameplate images must be 1500 x 300 pixels or smaller. Recommended artwork: 1500 x 300 pixels.'];
    }
    return ['mime' => $mime, 'extension' => $extensions[$mime], 'width' => $dimensions[0], 'height' => $dimensions[1]];
}

function nameplate_settings_definitions(): array
{
    $shared = ['owner' => 'app_settings', 'categoryId' => 'avatars-presence', 'subsectionId' => 'nameplate-upload',
        'subsectionLabel' => 'Chat Nameplates', 'subsectionOrder' => 25, 'adminVisible' => true, 'setupVisible' => true];
    return [
        $shared + ['id' => 'nameplate_max_size_mb', 'settingKey' => 'nameplate_max_size_mb', 'label' => 'Nameplate upload max MB',
            'type' => 'number', 'defaultValue' => 5.0, 'minimum' => 0.5, 'maximum' => 50, 'step' => 0.5, 'unit' => 'MB', 'order' => 10,
            'description' => 'Independent nameplate file-size policy. Avatar upload and display settings never change this limit.',
            'helpText' => 'Default 5 MB. JPEG, PNG, GIF and WebP are supported. Valid in-bounds images retain their dimensions and encoded animation. Oversized static artwork is reduced proportionally; oversized animations are rejected rather than flattened.'],
        $shared + ['id' => 'nameplate_artwork_dimensions', 'settingKey' => 'nameplate_artwork_dimensions', 'label' => 'Nameplate artwork dimensions',
            'type' => 'fixed', 'defaultValue' => true, 'fixedDisplayValue' => '1500 x 300 px maximum; 1500 x 300 px recommended',
            'mandatory' => true, 'safeToReset' => false, 'bulkOperations' => [], 'order' => 20,
            'fixedReason' => 'Nameplates preserve aspect ratio without square cropping. Smaller valid images are not enlarged. These dimensions are independent of avatars.'],
    ];
}
