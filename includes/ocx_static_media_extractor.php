<?php
declare(strict_types=1);
require_once __DIR__ . '/ocx_resource_archive.php';

/**
 * Bounded, static-only media extraction for legacy OCX/PE containers.
 *
 * This parser only reads bytes. It never loads the OCX as code, invokes COM,
 * registers a component, or retains the uploaded executable container.
 */

const OCX_STATIC_MEDIA_MAX_CONTAINER_BYTES = 67108864;
const OCX_STATIC_MEDIA_MAX_RESOURCES = 2048;
const OCX_STATIC_MEDIA_MAX_RESOURCE_BYTES = 16777216;
const OCX_STATIC_MEDIA_MAX_DIB_DIMENSION = 8192;
const OCX_STATIC_MEDIA_MAX_DIB_PIXELS = 16777216;
const OCX_STATIC_MEDIA_MAX_MIDI_TRACKS = 64;
const OCX_STATIC_MEDIA_MAX_MIDI_EVENTS = 200000;
const OCX_STATIC_MEDIA_MAX_MIDI_SECONDS = 600;
const OCX_STATIC_MEDIA_WAVE_SAMPLE_RATE = 11025;

function ocx_static_media_u16(string $bytes, int $offset): int
{
    if ($offset < 0 || $offset + 2 > strlen($bytes)) throw new RuntimeException('The OCX resource table is truncated.');
    return (int)(unpack('v', substr($bytes, $offset, 2))[1] ?? 0);
}

function ocx_static_media_u32(string $bytes, int $offset): int
{
    if ($offset < 0 || $offset + 4 > strlen($bytes)) throw new RuntimeException('The OCX resource table is truncated.');
    return (int)(unpack('V', substr($bytes, $offset, 4))[1] ?? 0);
}

function ocx_static_media_pe(string $path): array
{
    $size = is_file($path) ? (int)(filesize($path) ?: 0) : 0;
    if ($size < 512 || $size > OCX_STATIC_MEDIA_MAX_CONTAINER_BYTES) {
        throw new RuntimeException('The selected OCX is outside the bounded static-extraction size.');
    }
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) !== $size || substr($bytes, 0, 2) !== 'MZ') {
        throw new RuntimeException('The selected OCX is not a complete PE container.');
    }
    $peOffset = ocx_static_media_u32($bytes, 0x3c);
    if ($peOffset < 64 || $peOffset + 24 > $size || substr($bytes, $peOffset, 4) !== "PE\0\0") {
        throw new RuntimeException('The selected OCX has no valid PE header.');
    }
    $sectionCount = ocx_static_media_u16($bytes, $peOffset + 6);
    $optionalSize = ocx_static_media_u16($bytes, $peOffset + 20);
    if ($sectionCount < 1 || $sectionCount > 96 || $optionalSize < 112) {
        throw new RuntimeException('The selected OCX has an unsupported PE layout.');
    }
    $optional = $peOffset + 24;
    $magic = ocx_static_media_u16($bytes, $optional);
    $directoryOffset = match ($magic) {
        0x10b => $optional + 96,
        0x20b => $optional + 112,
        default => throw new RuntimeException('The selected OCX has an unsupported PE optional header.'),
    };
    if ($directoryOffset + 24 > $optional + $optionalSize) {
        throw new RuntimeException('The selected OCX has no bounded resource directory.');
    }
    $resourceRva = ocx_static_media_u32($bytes, $directoryOffset + 16);
    $resourceSize = ocx_static_media_u32($bytes, $directoryOffset + 20);
    if ($resourceRva < 1 || $resourceSize < 16 || $resourceSize > $size) {
        throw new RuntimeException('The selected OCX contains no usable static resources.');
    }
    $sections = [];
    $sectionOffset = $optional + $optionalSize;
    for ($index = 0; $index < $sectionCount; $index++) {
        $offset = $sectionOffset + ($index * 40);
        if ($offset + 40 > $size) throw new RuntimeException('The selected OCX section table is truncated.');
        $sections[] = [
            'virtualSize' => ocx_static_media_u32($bytes, $offset + 8),
            'virtualAddress' => ocx_static_media_u32($bytes, $offset + 12),
            'rawSize' => ocx_static_media_u32($bytes, $offset + 16),
            'rawOffset' => ocx_static_media_u32($bytes, $offset + 20),
        ];
    }
    $rvaToOffset = static function (int $rva, int $length = 1) use ($sections, $size): int {
        foreach ($sections as $section) {
            $start = (int)$section['virtualAddress'];
            $span = max((int)$section['virtualSize'], (int)$section['rawSize']);
            if ($rva < $start || $rva + $length > $start + $span) continue;
            $delta = $rva - $start;
            if ($delta < 0 || $delta + $length > (int)$section['rawSize']) break;
            $offset = (int)$section['rawOffset'] + $delta;
            if ($offset >= 0 && $offset + $length <= $size) return $offset;
            break;
        }
        throw new RuntimeException('An OCX resource points outside its PE section.');
    };
    $resourceOffset = $rvaToOffset($resourceRva, min($resourceSize, 16));
    return compact('bytes', 'size', 'resourceRva', 'resourceSize', 'resourceOffset', 'rvaToOffset');
}

function ocx_static_media_resource_name(string $bytes, int $base, int $raw): string|int
{
    if (($raw & 0x80000000) === 0) return $raw;
    $offset = $base + ($raw & 0x7fffffff);
    $length = ocx_static_media_u16($bytes, $offset);
    if ($length < 1 || $length > 128 || $offset + 2 + ($length * 2) > strlen($bytes)) {
        throw new RuntimeException('An OCX resource name is outside its safe boundary.');
    }
    $encoded = substr($bytes, $offset + 2, $length * 2);
    $decoded = function_exists('mb_convert_encoding')
        ? mb_convert_encoding($encoded, 'UTF-8', 'UTF-16LE')
        : (iconv('UTF-16LE', 'UTF-8//IGNORE', $encoded) ?: '');
    $decoded = trim((string)preg_replace('/[^A-Za-z0-9@._-]+/', '_', $decoded), '._-');
    if ($decoded === '') throw new RuntimeException('An OCX resource name is unusable.');
    return $decoded;
}

function ocx_static_media_resources(string $path): array
{
    $pe = ocx_static_media_pe($path);
    $bytes = $pe['bytes'];
    $base = (int)$pe['resourceOffset'];
    $boundedEnd = min(strlen($bytes), $base + (int)$pe['resourceSize']);
    $resources = [];
    $walk = function (int $relativeOffset, int $depth, array $names) use (&$walk, &$resources, $bytes, $base, $boundedEnd, $pe): void {
        if ($depth > 3 || count($resources) >= OCX_STATIC_MEDIA_MAX_RESOURCES) {
            throw new RuntimeException('The OCX resource inventory exceeds its static-extraction boundary.');
        }
        $directory = $base + $relativeOffset;
        if ($directory < $base || $directory + 16 > $boundedEnd) throw new RuntimeException('The OCX resource directory is truncated.');
        $entries = ocx_static_media_u16($bytes, $directory + 12) + ocx_static_media_u16($bytes, $directory + 14);
        if ($entries < 0 || $entries > OCX_STATIC_MEDIA_MAX_RESOURCES || $directory + 16 + ($entries * 8) > $boundedEnd) {
            throw new RuntimeException('The OCX resource directory exceeds its safe entry boundary.');
        }
        for ($index = 0; $index < $entries; $index++) {
            $entry = $directory + 16 + ($index * 8);
            $name = ocx_static_media_resource_name($bytes, $base, ocx_static_media_u32($bytes, $entry));
            $target = ocx_static_media_u32($bytes, $entry + 4);
            if (($target & 0x80000000) !== 0) {
                $walk($target & 0x7fffffff, $depth + 1, [...$names, $name]);
                continue;
            }
            $dataEntry = $base + $target;
            if ($dataEntry < $base || $dataEntry + 16 > $boundedEnd) throw new RuntimeException('An OCX resource data entry is truncated.');
            $dataRva = ocx_static_media_u32($bytes, $dataEntry);
            $dataSize = ocx_static_media_u32($bytes, $dataEntry + 4);
            if ($dataSize < 1 || $dataSize > OCX_STATIC_MEDIA_MAX_RESOURCE_BYTES) continue;
            $dataOffset = ($pe['rvaToOffset'])($dataRva, $dataSize);
            $resources[] = [
                'type' => $names[0] ?? null,
                'name' => $names[1] ?? $name,
                'language' => $names[2] ?? ($depth >= 2 ? $name : null),
                'bytes' => substr($bytes, $dataOffset, $dataSize),
            ];
            if (count($resources) > OCX_STATIC_MEDIA_MAX_RESOURCES) {
                throw new RuntimeException('The OCX contains too many static resources.');
            }
        }
    };
    $walk(0, 1, []);
    return $resources;
}

function ocx_static_media_dib_to_png(string $dib): ?string
{
    if (!extension_loaded('gd') || strlen($dib) < 16) return null;
    $headerSize = ocx_static_media_u32($dib, 0);
    $paletteBytes = 0;
    $maskBytes = 0;
    if ($headerSize === 12 && strlen($dib) >= 12) {
        $width = ocx_static_media_u16($dib, 4);
        $height = ocx_static_media_u16($dib, 6);
        $planes = ocx_static_media_u16($dib, 8);
        $bits = ocx_static_media_u16($dib, 10);
        if ($bits <= 8) $paletteBytes = (1 << $bits) * 3;
    } elseif ($headerSize >= 40 && $headerSize <= strlen($dib)) {
        $dimensions = unpack('Vwidth/Vheight', substr($dib, 4, 8));
        $width = (int)($dimensions['width'] ?? 0);
        $rawHeight = (int)($dimensions['height'] ?? 0);
        if (($rawHeight & 0x80000000) !== 0) $rawHeight -= 0x100000000;
        $height = abs($rawHeight);
        $planes = ocx_static_media_u16($dib, 12);
        $bits = ocx_static_media_u16($dib, 14);
        $compression = ocx_static_media_u32($dib, 16);
        $colors = ocx_static_media_u32($dib, 32);
        if ($bits <= 8) $paletteBytes = ($colors > 0 ? $colors : (1 << $bits)) * 4;
        if ($headerSize === 40 && $compression === 3) $maskBytes = 12;
        if ($headerSize === 40 && $compression === 6) $maskBytes = 16;
    } else {
        return null;
    }
    if (
        $width < 1 || $height < 1 || $planes !== 1
        || $width > OCX_STATIC_MEDIA_MAX_DIB_DIMENSION
        || $height > OCX_STATIC_MEDIA_MAX_DIB_DIMENSION
        || $width > intdiv(OCX_STATIC_MEDIA_MAX_DIB_PIXELS, $height)
        || !in_array($bits, [1, 4, 8, 16, 24, 32], true)
    ) return null;
    $pixelOffset = 14 + $headerSize + $maskBytes + $paletteBytes;
    if ($pixelOffset < 14 || $pixelOffset > 14 + strlen($dib)) return null;
    $bmp = 'BM' . pack('VvvV', 14 + strlen($dib), 0, 0, $pixelOffset) . $dib;
    $image = @imagecreatefromstring($bmp);
    if ($image === false) return null;
    ob_start();
    $written = imagepng($image, null, 9);
    $png = ob_get_clean();
    imagedestroy($image);
    return $written && is_string($png) && str_starts_with($png, "\x89PNG\r\n\x1a\n") ? $png : null;
}

function ocx_static_media_payload(array $resource): ?array
{
    $bytes = (string)($resource['bytes'] ?? '');
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) return ['extension' => 'png', 'bytes' => $bytes];
    if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) return ['extension' => 'gif', 'bytes' => $bytes];
    if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WAVE') return ['extension' => 'wav', 'bytes' => $bytes];
    if (strlen($bytes) >= 20 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'RMID') return ['extension' => 'rmid', 'bytes' => $bytes];
    if (str_starts_with($bytes, 'ID3') || (strlen($bytes) >= 2 && ord($bytes[0]) === 0xff && (ord($bytes[1]) & 0xe0) === 0xe0)) return ['extension' => 'mp3', 'bytes' => $bytes];
    if ((int)($resource['type'] ?? -1) === 2) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            throw new RuntimeException('Static OCX bitmap extraction requires the PHP GD image extension. Enable GD, then retry the unchanged OCX.');
        }
        $png = ocx_static_media_dib_to_png($bytes);
        if ($png !== null) return ['extension' => 'png', 'bytes' => $png];
    }
    return null;
}

function ocx_static_media_be16(string $bytes, int $offset): int
{
    if ($offset < 0 || $offset + 2 > strlen($bytes)) throw new RuntimeException('The source RMID is truncated.');
    return (int)(unpack('n', substr($bytes, $offset, 2))[1] ?? 0);
}

function ocx_static_media_be32(string $bytes, int $offset): int
{
    if ($offset < 0 || $offset + 4 > strlen($bytes)) throw new RuntimeException('The source RMID is truncated.');
    return (int)(unpack('N', substr($bytes, $offset, 4))[1] ?? 0);
}

function ocx_static_media_midi_variable(string $bytes, int &$offset, int $end): int
{
    $value = 0;
    for ($count = 0; $count < 4; $count++) {
        if ($offset >= $end) throw new RuntimeException('The source RMID variable-length value is truncated.');
        $byte = ord($bytes[$offset++]);
        $value = ($value << 7) | ($byte & 0x7f);
        if (($byte & 0x80) === 0) return $value;
    }
    throw new RuntimeException('The source RMID variable-length value is outside its safe boundary.');
}

function ocx_static_media_rmid_midi(string $rmid): string
{
    if (strlen($rmid) < 20 || substr($rmid, 0, 4) !== 'RIFF' || substr($rmid, 8, 4) !== 'RMID') {
        throw new RuntimeException('The source music is not a bounded RIFF/RMID container.');
    }
    $riffLength = ocx_static_media_u32($rmid, 4);
    if ($riffLength + 8 > strlen($rmid)) throw new RuntimeException('The source RMID container is truncated.');
    $offset = 12;
    while ($offset + 8 <= min(strlen($rmid), $riffLength + 8)) {
        $kind = substr($rmid, $offset, 4);
        $length = ocx_static_media_u32($rmid, $offset + 4);
        $start = $offset + 8;
        if ($length < 1 || $start + $length > strlen($rmid)) throw new RuntimeException('A source RMID chunk is outside its safe boundary.');
        if ($kind === 'data') {
            $midi = substr($rmid, $start, $length);
            if (!str_starts_with($midi, 'MThd')) throw new RuntimeException('The source RMID data chunk has no MIDI header.');
            return $midi;
        }
        $offset = $start + $length + ($length % 2);
    }
    throw new RuntimeException('The source RMID contains no MIDI data chunk.');
}

/**
 * Convert one bounded RIFF/RMID score to deterministic mono PCM for browser
 * playback. The score is parsed as data only; no operating-system MIDI,
 * process, COM, module, codec, or registration path is used.
 */
function ocx_static_media_rmid_to_wave(string $rmid): string
{
    $midi = ocx_static_media_rmid_midi($rmid);
    if (strlen($midi) < 14 || substr($midi, 0, 4) !== 'MThd') throw new RuntimeException('The source MIDI header is unavailable.');
    $headerLength = ocx_static_media_be32($midi, 4);
    if ($headerLength < 6 || 8 + $headerLength > strlen($midi)) throw new RuntimeException('The source MIDI header is outside its safe boundary.');
    $format = ocx_static_media_be16($midi, 8);
    $trackCount = ocx_static_media_be16($midi, 10);
    $division = ocx_static_media_be16($midi, 12);
    if (!in_array($format, [0, 1], true) || $trackCount < 1 || $trackCount > OCX_STATIC_MEDIA_MAX_MIDI_TRACKS
        || $division < 1 || ($division & 0x8000) !== 0) {
        throw new RuntimeException('The source MIDI timing layout is unsupported.');
    }

    $offset = 8 + $headerLength;
    $tempos = [['tick' => 0, 'microseconds' => 500000]];
    $notes = [];
    $eventCount = 0;
    for ($trackIndex = 0; $trackIndex < $trackCount; $trackIndex++) {
        if ($offset + 8 > strlen($midi) || substr($midi, $offset, 4) !== 'MTrk') {
            throw new RuntimeException('A source MIDI track is unavailable.');
        }
        $trackLength = ocx_static_media_be32($midi, $offset + 4);
        $trackOffset = $offset + 8;
        $trackEnd = $trackOffset + $trackLength;
        if ($trackLength < 1 || $trackEnd > strlen($midi)) throw new RuntimeException('A source MIDI track is outside its safe boundary.');
        $tick = 0;
        $runningStatus = 0;
        $programs = array_fill(0, 16, 0);
        $active = [];
        while ($trackOffset < $trackEnd) {
            $tick += ocx_static_media_midi_variable($midi, $trackOffset, $trackEnd);
            if (++$eventCount > OCX_STATIC_MEDIA_MAX_MIDI_EVENTS) throw new RuntimeException('The source MIDI event inventory exceeds its safe boundary.');
            if ($trackOffset >= $trackEnd) throw new RuntimeException('A source MIDI event is truncated.');
            $status = ord($midi[$trackOffset]);
            $runningData = false;
            if ($status >= 0x80) {
                $trackOffset++;
                if ($status < 0xf0) $runningStatus = $status;
            } else {
                if ($runningStatus < 0x80 || $runningStatus >= 0xf0) throw new RuntimeException('The source MIDI running status is invalid.');
                $status = $runningStatus;
                $runningData = true;
            }
            if ($status === 0xff) {
                if ($trackOffset >= $trackEnd) throw new RuntimeException('A source MIDI meta event is truncated.');
                $type = ord($midi[$trackOffset++]);
                $length = ocx_static_media_midi_variable($midi, $trackOffset, $trackEnd);
                if ($trackOffset + $length > $trackEnd) throw new RuntimeException('A source MIDI meta payload is truncated.');
                if ($type === 0x51 && $length === 3) {
                    $tempo = (ord($midi[$trackOffset]) << 16) | (ord($midi[$trackOffset + 1]) << 8) | ord($midi[$trackOffset + 2]);
                    if ($tempo >= 10000 && $tempo <= 10000000) $tempos[] = ['tick' => $tick, 'microseconds' => $tempo];
                }
                $trackOffset += $length;
                if ($type === 0x2f) break;
                continue;
            }
            if ($status === 0xf0 || $status === 0xf7) {
                $length = ocx_static_media_midi_variable($midi, $trackOffset, $trackEnd);
                if ($trackOffset + $length > $trackEnd) throw new RuntimeException('A source MIDI system payload is truncated.');
                $trackOffset += $length;
                continue;
            }
            if ($status >= 0xf0) throw new RuntimeException('The source MIDI contains an unsupported system event.');
            $kind = $status & 0xf0;
            $channel = $status & 0x0f;
            $dataLength = in_array($kind, [0xc0, 0xd0], true) ? 1 : 2;
            $data = [];
            for ($dataIndex = 0; $dataIndex < $dataLength; $dataIndex++) {
                if ($runningData && $dataIndex === 0) {
                    $data[] = ord($midi[$trackOffset++]);
                    $runningData = false;
                } else {
                    if ($trackOffset >= $trackEnd) throw new RuntimeException('A source MIDI channel event is truncated.');
                    $data[] = ord($midi[$trackOffset++]);
                }
            }
            if ($kind === 0xc0) {
                $programs[$channel] = $data[0] & 0x7f;
                continue;
            }
            if (!in_array($kind, [0x80, 0x90], true)) continue;
            $note = $data[0] & 0x7f;
            $velocity = $data[1] & 0x7f;
            $key = $channel . ':' . $note;
            $isOn = $kind === 0x90 && $velocity > 0;
            if ($isOn) {
                $active[$key][] = ['tick' => $tick, 'velocity' => $velocity, 'program' => $programs[$channel], 'channel' => $channel, 'note' => $note];
            } elseif (!empty($active[$key])) {
                $started = array_shift($active[$key]);
                if ($tick > (int)$started['tick']) $notes[] = $started + ['endTick' => $tick];
            }
        }
        foreach ($active as $entries) foreach ($entries as $started) {
            if ($tick > (int)$started['tick']) $notes[] = $started + ['endTick' => $tick];
        }
        $offset = $trackEnd;
    }
    if ($notes === []) throw new RuntimeException('The source RMID contains no bounded playable notes.');

    usort($tempos, static fn(array $a, array $b): int => $a['tick'] <=> $b['tick']);
    $tempoByTick = [];
    foreach ($tempos as $tempo) $tempoByTick[(int)$tempo['tick']] = (int)$tempo['microseconds'];
    ksort($tempoByTick, SORT_NUMERIC);
    $segments = [];
    $lastTick = 0;
    $lastMicros = 0.0;
    $lastTempo = 500000;
    foreach ($tempoByTick as $tempoTick => $tempo) {
        $tempoTick = (int)$tempoTick;
        if ($tempoTick > $lastTick) $lastMicros += (($tempoTick - $lastTick) * $lastTempo) / $division;
        $segments[] = ['tick' => $tempoTick, 'micros' => $lastMicros, 'tempo' => $tempo];
        $lastTick = $tempoTick;
        $lastTempo = $tempo;
    }
    $tickSeconds = static function (int $tick) use ($segments, $division): float {
        $segment = $segments[0];
        foreach ($segments as $candidate) {
            if ((int)$candidate['tick'] > $tick) break;
            $segment = $candidate;
        }
        return ((float)$segment['micros'] + (($tick - (int)$segment['tick']) * (int)$segment['tempo']) / $division) / 1000000.0;
    };

    $sampleRate = OCX_STATIC_MEDIA_WAVE_SAMPLE_RATE;
    $renderNotes = [];
    $maximumEnd = 0;
    foreach ($notes as $index => $note) {
        $start = max(0, (int)round($tickSeconds((int)$note['tick']) * $sampleRate));
        $end = max($start + 1, (int)round($tickSeconds((int)$note['endTick']) * $sampleRate));
        $releaseEnd = $end + (int)round(0.08 * $sampleRate);
        $maximumEnd = max($maximumEnd, $releaseEnd);
        $renderNotes[$index] = $note + ['startSample' => $start, 'endSample' => $end, 'releaseEndSample' => $releaseEnd];
    }
    if ($maximumEnd < 1 || $maximumEnd > OCX_STATIC_MEDIA_MAX_MIDI_SECONDS * $sampleRate) {
        throw new RuntimeException('The source RMID duration exceeds its safe boundary.');
    }
    $starts = [];
    foreach ($renderNotes as $index => $note) $starts[(int)$note['startSample']][] = $index;
    $active = [];
    $pcm = '';
    $chunk = [];
    $twoPi = 2.0 * M_PI;
    for ($sample = 0; $sample < $maximumEnd; $sample++) {
        foreach ($starts[$sample] ?? [] as $index) $active[$index] = $renderNotes[$index];
        $mixed = 0.0;
        foreach ($active as $index => $note) {
            if ($sample >= (int)$note['releaseEndSample']) {
                unset($active[$index]);
                continue;
            }
            $age = $sample - (int)$note['startSample'];
            $frequency = 440.0 * (2.0 ** (((int)$note['note'] - 69) / 12.0));
            $phase = $twoPi * $frequency * $age / $sampleRate;
            $attack = min(1.0, $age / max(1.0, 0.01 * $sampleRate));
            $release = $sample <= (int)$note['endSample']
                ? 1.0
                : max(0.0, 1.0 - (($sample - (int)$note['endSample']) / max(1.0, 0.08 * $sampleRate)));
            $amplitude = ((int)$note['velocity'] / 127.0) * $attack * $release;
            $programColor = 0.12 + (((int)$note['program'] % 8) * 0.02);
            $wave = sin($phase) + ($programColor * sin($phase * 2.0));
            if ((int)$note['channel'] === 9) $wave = 0.7 * sin($phase * 1.5) + 0.3 * sin($phase * 3.0);
            $mixed += $wave * $amplitude;
        }
        $value = (int)round(max(-1.0, min(1.0, $mixed * 0.16)) * 32767);
        $chunk[] = $value < 0 ? $value + 65536 : $value;
        if (count($chunk) >= 4096) {
            $pcm .= pack('v*', ...$chunk);
            $chunk = [];
        }
    }
    if ($chunk !== []) $pcm .= pack('v*', ...$chunk);
    if ($pcm === '' || strlen($pcm) > OCX_STATIC_MEDIA_MAX_RESOURCE_BYTES) {
        throw new RuntimeException('The derived browser wave exceeds its safe output boundary.');
    }
    $format = pack('vvVVvv', 1, 1, $sampleRate, $sampleRate * 2, 2, 16);
    return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
        . 'fmt ' . pack('V', strlen($format)) . $format
        . 'data' . pack('V', strlen($pcm)) . $pcm;
}

function ocx_static_media_derive_slot_bytes(string $bytes, string $extension, array $slot): string
{
    $owner = (string)($slot['derivationOwner'] ?? '');
    if ($owner === '' || $extension === strtolower((string)($slot['kind'] ?? ''))) return $bytes;
    if ($owner === 'deterministic-static-rmid-browser-wave-v1' && $extension === 'rmid') {
        return ocx_static_media_rmid_to_wave($bytes);
    }
    throw new RuntimeException('The selected source media requires an unsupported static derivation.');
}

function ocx_static_media_candidate_names(array $resource, string $extension): array
{
    $type = $resource['type'] ?? null;
    $name = $resource['name'] ?? null;
    $parts = [];
    if (is_string($name) && $name !== '') $parts[] = $name;
    if (is_int($name) || ctype_digit((string)$name)) $parts[] = (string)$name;
    if (is_string($type) && $type !== '' && $name !== null) $parts[] = $type . '_' . $name;
    $names = [];
    foreach (array_unique($parts) as $part) {
        $part = strtolower(trim((string)$part, '._-'));
        if ($part === '') continue;
        $names[] = $part . '.' . $extension;
        if ($extension === 'png') $names[] = $part . '@2x.png';
    }
    return array_values(array_unique($names));
}

/**
 * Stage only allowlisted resources from an OCX into an existing private pack
 * attempt. The caller's validator is authoritative for signature, dimensions,
 * hashes, and slot-specific requirements.
 */
function ocx_static_media_stage(
    string $containerPath,
    string $attempt,
    array $acceptedNameMap,
    array $slots,
    callable $validateSlot
): array {
    $staged = 0;
    $recognizedResources = 0;
    $seenSlots = [];
    $resources = ocx_static_media_resources($containerPath);
    foreach ($resources as $resource) {
        $payload = ocx_static_media_payload($resource);
        if ($payload === null) continue;
        foreach (ocx_static_media_candidate_names($resource, (string)$payload['extension']) as $candidate) {
            $slot = $acceptedNameMap[strtolower($candidate)] ?? null;
            if (!is_string($slot) || !isset($slots[$slot])) continue;
            $recognizedResources++;
            if (isset($seenSlots[$slot])) {
                throw new RuntimeException('The selected OCX contains duplicate resources for one media slot.');
            }
            $seenSlots[$slot] = true;
            $target = $attempt . DIRECTORY_SEPARATOR . (string)$slots[$slot]['installName'];
            if (is_file($target)) break;
            $slotBytes = ocx_static_media_derive_slot_bytes(
                (string)$payload['bytes'],
                (string)$payload['extension'],
                (array)$slots[$slot]
            );
            if (strlen($slotBytes) > (int)$slots[$slot]['maximumBytes']) break;
            $temporary = $attempt . DIRECTORY_SEPARATOR . '.ocx-static-' . bin2hex(random_bytes(8));
            if (file_put_contents($temporary, $slotBytes, LOCK_EX) === false) {
                throw new RuntimeException('A statically extracted OCX resource could not be staged privately.');
            }
            try {
                if (!rename($temporary, $target)) throw new RuntimeException('A statically extracted OCX resource could not be staged privately.');
                $validation = $validateSlot($slot, $attempt);
                if (($validation['state'] ?? '') !== 'installed') {
                    @unlink($target);
                    break;
                }
                $staged++;
            } finally {
                if (is_file($temporary)) @unlink($temporary);
            }
            break;
        }
    }
    if ($recognizedResources < 1) throw new RuntimeException('The selected OCX contains no recognized media resources for this game.');
    $retained = ocx_resource_archive_store($resources, $attempt);
    return ['recognizedResources' => $recognizedResources, 'stagedSlots' => $staged, 'retainedResources' => $retained, 'containerRetained' => false, 'executionUsed' => false];
}
