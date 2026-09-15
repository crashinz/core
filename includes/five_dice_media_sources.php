<?php
declare(strict_types=1);

/** Static-only Five Dice source normalization. Installed images keep the renderer's 2x geometry. */
function five_dice_media_pixel_hash(string $path): string
{
    if (!function_exists('imagecreatefrompng')) return '';
    $image = @imagecreatefrompng($path);
    if ($image === false) return '';
    $hash = hash_init('sha256');
    for ($y = 0; $y < imagesy($image); $y++) {
        $row = '';
        for ($x = 0; $x < imagesx($image); $x++) {
            $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $row .= pack('C4', $c['red'], $c['green'], $c['blue'], (int)round((127 - $c['alpha']) * 255 / 127));
        }
        hash_update($hash, $row);
    }
    imagedestroy($image);
    return hash_final($hash);
}

function five_dice_media_source_inventory(string $directory): array
{
    $path = $directory . DIRECTORY_SEPARATOR . '.source-selection.json';
    if (!is_file($path) || filesize($path) > 32768) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function five_dice_media_source_rank(array $validated, array $inventory): int
{
    $entry = $inventory[$validated['slot']] ?? [];
    if (isset($entry['sha256']) && hash_equals((string)$validated['sha256'], (string)$entry['sha256'])) {
        return ($entry['rank'] ?? 2) === 1 ? 1 : 2;
    }
    // Older installed packs contain supplied 2x PNGs and MP3 music, without provenance metadata.
    return 2;
}

function five_dice_media_stage_source(string $slot, string $bytes, string $attempt): bool
{
    $definition = five_dice_media_pack_slots()[$slot] ?? null;
    if (!$definition || strlen($bytes) < 1 || strlen($bytes) > (int)$definition['maximumBytes']) {
        throw new RuntimeException('The source media exceeds its safe slot boundary.');
    }
    $rank = 2;
    if ($definition['kind'] === 'image') {
        $size = @getimagesizefromstring($bytes);
        $width = (int)$definition['requiredWidth']; $height = (int)$definition['requiredHeight'];
        if (!is_array($size) || !in_array($size['mime'] ?? '', ['image/png', 'image/gif', 'image/bmp', 'image/x-ms-bmp'], true)
            || !(([$size[0], $size[1]] === [$width, $height]) || ([$size[0] * 2, $size[1] * 2] === [$width, $height]))) {
            throw new RuntimeException('Choose the original-size or doubled artwork for this media slot.');
        }
        $rank = $size[0] === $width ? 2 : 1;
        if ($rank === 1 || $size['mime'] !== 'image/png') {
            $source = @imagecreatefromstring($bytes);
            if ($source === false) throw new RuntimeException('The original artwork could not be decoded.');
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false); imagesavealpha($image, true);
            imagecopyresized($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
            ob_start(); $written = imagepng($image, null, 9); $bytes = (string)ob_get_clean();
            imagedestroy($image); imagedestroy($source);
            if (!$written) throw new RuntimeException('The original artwork could not be prepared.');
        }
    } elseif ($slot === 'background-music') {
        if (str_starts_with($bytes, 'MThd')) {
            $bytes = 'RIFF' . pack('V', 12 + strlen($bytes) + (strlen($bytes) % 2)) . 'RMIDdata' . pack('V', strlen($bytes)) . $bytes . (strlen($bytes) % 2 ? "\0" : '');
        }
        if (substr($bytes, 8, 4) === 'RMID') { $bytes = ocx_static_media_rmid_to_wave($bytes); $rank = 1; }
    }
    if (strlen($bytes) > (int)$definition['maximumBytes']) throw new RuntimeException('The prepared media exceeds its safe slot boundary.');
    $temporary = $attempt . DIRECTORY_SEPARATOR . '.source-check-' . bin2hex(random_bytes(8));
    if (!mkdir($temporary, 0700)) throw new RuntimeException('The private source check could not be created.');
    $name = (string)$definition['installName']; $path = $temporary . DIRECTORY_SEPARATOR . $name;
    try {
        if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new RuntimeException('The source media could not be staged.');
        $validated = five_dice_media_pack_validate_slot($slot, $temporary);
        if ($validated['state'] !== 'installed') throw new RuntimeException((string)$validated['reason']);
        $inventory = five_dice_media_source_inventory($attempt);
        $existing = five_dice_media_pack_validate_slot($slot, $attempt);
        if ($existing['state'] === 'installed') {
            $oldRank = five_dice_media_source_rank($existing, $inventory);
            if ($oldRank > $rank) return false;
            if ($oldRank === $rank) {
                $equal = hash_equals($existing['sha256'], $validated['sha256']);
                if (!$equal && $definition['kind'] === 'image') $equal = hash_equals(five_dice_media_pixel_hash($existing['path']), five_dice_media_pixel_hash($path));
                if ($equal) return false;
                throw new RuntimeException('The selected pack contains conflicting copies of the same media slot.');
            }
        }
        if (!rename($path, $attempt . DIRECTORY_SEPARATOR . $name)) throw new RuntimeException('The validated source could not be selected.');
        $inventory[$slot] = ['rank' => $rank, 'sha256' => $validated['sha256']];
        if (file_put_contents($attempt . DIRECTORY_SEPARATOR . '.source-selection.json', json_encode($inventory, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('The selected source could not be recorded.');
        }
        return true;
    } finally {
        if (is_file($path)) unlink($path);
        rmdir($temporary);
    }
}

function five_dice_media_stage_ocx(string $container, string $attempt): array
{
    $map = five_dice_media_pack_accepted_filename_map(); $seen = []; $staged = 0;
    foreach (ocx_static_media_resources($container) as $resource) {
        $payload = ocx_static_media_payload($resource);
        if (!$payload) continue;
        foreach (ocx_static_media_candidate_names($resource, $payload['extension']) as $name) {
            $slot = $map[$name] ?? null;
            if (!$slot) continue;
            if (isset($seen[$slot])) throw new RuntimeException('The selected OCX contains duplicate resources for one media slot.');
            $seen[$slot] = true;
            // Inspect image dimensions even in a modified OCX. Prepared music avoids redundant MIDI synthesis.
            $existing = five_dice_media_pack_validate_slot($slot, $attempt);
            if ($existing['state'] !== 'installed' || ($existing['kind'] ?? '') === 'image') {
                $staged += (int)five_dice_media_stage_source($slot, $payload['bytes'], $attempt);
            }
            break;
        }
    }
    if (!$seen) throw new RuntimeException('The selected OCX contains no recognized Five Dice media.');
    return ['recognizedResources' => count($seen), 'stagedSlots' => $staged, 'containerRetained' => false, 'executionUsed' => false];
}
