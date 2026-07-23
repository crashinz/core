<?php
declare(strict_types=1);

/**
 * Canonical AGST/package and protected-media owner for Gesture Checkpoint Part 4.
 *
 * Catie's original Gesture Maker design informed the bounded GIF/audio/package
 * workflow. Archive parsing, identity, authorization, storage, provenance, and
 * persistence are server-owned reimplementations and are not attributed to the
 * original client-only reference implementation.
 */

const GESTURE_PACKAGE_SCHEMA = 'chatspace.agst';
const GESTURE_PACKAGE_VERSION = 1;
const GESTURE_PACKAGE_MAX_COMPRESSED = 31457280;
const GESTURE_PACKAGE_MAX_EXPANDED = 36700160;
const GESTURE_PACKAGE_MAX_ENTRY = 26214400;
const GESTURE_PACKAGE_MAX_ENTRIES = 20;
const GESTURE_PACKAGE_MAX_RATIO = 200;
const GESTURE_PACKAGE_MAX_MANIFEST = 65536;
const GESTURE_ANIMATION_MAX_BYTES = 26214400;
const GESTURE_POSTER_MAX_BYTES = 5242880;
const GESTURE_AUDIO_MAX_BYTES = 10485760;
const GESTURE_MEDIA_MAX_DIMENSION = 2048;
const GESTURE_MEDIA_MAX_PIXELS = 4194304;
const GESTURE_ANIMATION_MAX_FRAMES = 900;
const GESTURE_MEDIA_MAX_DURATION_MS = 60000;

function gesture_package_add_columns(PDO $pdo): void
{
    $definitions = db_driver($pdo) === 'mysql' ? [
        'package_generation' => 'INT NOT NULL DEFAULT 0',
        'package_has_poster' => 'INT NOT NULL DEFAULT 0',
        'package_status' => "VARCHAR(32) NOT NULL DEFAULT 'legacy-unverified'",
        'package_version' => 'INT NOT NULL DEFAULT 0',
        'package_sha256' => 'VARCHAR(64) DEFAULT NULL',
        'content_sha256' => 'VARCHAR(64) DEFAULT NULL',
        'media_access_token' => 'VARCHAR(64) DEFAULT NULL',
        'package_updated_at' => 'DATETIME DEFAULT NULL',
    ] : [
        'package_generation' => 'INTEGER NOT NULL DEFAULT 0',
        'package_has_poster' => 'INTEGER NOT NULL DEFAULT 0',
        'package_status' => "TEXT NOT NULL DEFAULT 'legacy-unverified'",
        'package_version' => 'INTEGER NOT NULL DEFAULT 0',
        'package_sha256' => 'TEXT DEFAULT NULL',
        'content_sha256' => 'TEXT DEFAULT NULL',
        'media_access_token' => 'TEXT DEFAULT NULL',
        'package_updated_at' => 'TEXT DEFAULT NULL',
    ];
    $columns = gesture_catalog_columns($pdo, 'gestures');
    foreach ($definitions as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE gestures ADD COLUMN {$column} {$definition}");
        }
    }
}

function gesture_package_create_tables(PDO $pdo): void
{
    if (db_driver($pdo) === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gesture_package_generations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gesture_id INT NOT NULL,
            generation INT NOT NULL,
            package_version INT NOT NULL DEFAULT 0,
            manifest_json LONGTEXT NOT NULL,
            package_storage_name VARCHAR(191) DEFAULT NULL,
            animation_storage_name VARCHAR(255) DEFAULT NULL,
            animation_mime VARCHAR(64) DEFAULT NULL,
            animation_size INT NOT NULL DEFAULT 0,
            animation_width INT DEFAULT NULL,
            animation_height INT DEFAULT NULL,
            animation_frames INT DEFAULT NULL,
            animation_duration_ms INT DEFAULT NULL,
            poster_storage_name VARCHAR(255) DEFAULT NULL,
            poster_mime VARCHAR(64) DEFAULT NULL,
            poster_size INT NOT NULL DEFAULT 0,
            poster_width INT DEFAULT NULL,
            poster_height INT DEFAULT NULL,
            audio_storage_name VARCHAR(255) DEFAULT NULL,
            audio_mime VARCHAR(64) DEFAULT NULL,
            audio_size INT NOT NULL DEFAULT 0,
            audio_duration_ms INT DEFAULT NULL,
            audio_channels INT DEFAULT NULL,
            audio_sample_rate INT DEFAULT NULL,
            content_sha256 VARCHAR(64) DEFAULT NULL,
            package_sha256 VARCHAR(64) DEFAULT NULL,
            media_access_token VARCHAR(64) NOT NULL,
            compatibility VARCHAR(48) NOT NULL,
            validation_status VARCHAR(32) NOT NULL,
            created_by_user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_gesture_package_generation (gesture_id, generation),
            FOREIGN KEY(gesture_id) REFERENCES gestures(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS gesture_package_generations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        gesture_id INTEGER NOT NULL,
        generation INTEGER NOT NULL,
        package_version INTEGER NOT NULL DEFAULT 0,
        manifest_json TEXT NOT NULL,
        package_storage_name TEXT DEFAULT NULL,
        animation_storage_name TEXT DEFAULT NULL,
        animation_mime TEXT DEFAULT NULL,
        animation_size INTEGER NOT NULL DEFAULT 0,
        animation_width INTEGER DEFAULT NULL,
        animation_height INTEGER DEFAULT NULL,
        animation_frames INTEGER DEFAULT NULL,
        animation_duration_ms INTEGER DEFAULT NULL,
        poster_storage_name TEXT DEFAULT NULL,
        poster_mime TEXT DEFAULT NULL,
        poster_size INTEGER NOT NULL DEFAULT 0,
        poster_width INTEGER DEFAULT NULL,
        poster_height INTEGER DEFAULT NULL,
        audio_storage_name TEXT DEFAULT NULL,
        audio_mime TEXT DEFAULT NULL,
        audio_size INTEGER NOT NULL DEFAULT 0,
        audio_duration_ms INTEGER DEFAULT NULL,
        audio_channels INTEGER DEFAULT NULL,
        audio_sample_rate INTEGER DEFAULT NULL,
        content_sha256 TEXT DEFAULT NULL,
        package_sha256 TEXT DEFAULT NULL,
        media_access_token TEXT NOT NULL,
        compatibility TEXT NOT NULL,
        validation_status TEXT NOT NULL,
        created_by_user_id INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(gesture_id, generation),
        FOREIGN KEY(gesture_id) REFERENCES gestures(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    )");
}

function gesture_package_legacy_manifest(array $row): array
{
    $animationPath = (string)($row['gif_path'] ?? '');
    $audioPath = (string)($row['audio_path'] ?? '');
    $media = [
        'animation' => [
            'entry' => basename(str_replace('\\', '/', $animationPath)) ?: 'animation.gif',
            'mime' => 'image/gif',
        ],
    ];
    if ($audioPath !== '') {
        $media['audio'] = [
            'entry' => basename(str_replace('\\', '/', $audioPath)) ?: 'audio.mp3',
            'mime' => 'audio/mpeg',
        ];
    }
    return [
        'schema' => GESTURE_PACKAGE_SCHEMA,
        'version' => 0,
        'id' => (string)$row['public_id'],
        'title' => (string)($row['title'] ?: $row['name']),
        'text' => (string)$row['gesture_text'],
        'creator_credit' => (string)($row['creator_credit'] ?: 'Unknown creator'),
        'media' => $media,
        'compatibility' => 'legacy-toc-or-precanonical',
    ];
}

function gesture_package_backfill(PDO $pdo): void
{
    $rows = $pdo->query('SELECT * FROM gestures WHERE package_generation < 1 ORDER BY id ASC')->fetchAll();
    if (!$rows) return;
    $insert = $pdo->prepare(
        'INSERT INTO gesture_package_generations '
        . '(gesture_id,generation,package_version,manifest_json,animation_storage_name,animation_mime,poster_storage_name,poster_mime,audio_storage_name,audio_mime,media_access_token,compatibility,validation_status,created_by_user_id) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $update = $pdo->prepare(
        "UPDATE gestures SET package_generation = 1, package_status = 'legacy-unverified', package_version = 0, media_access_token = ?, package_updated_at = COALESCE(package_updated_at, updated_at) WHERE id = ?"
    );
    foreach ($rows as $row) {
        $token = bin2hex(random_bytes(24));
        $animation = (string)($row['gif_path'] ?? '');
        $poster = '';
        $audio = (string)($row['audio_path'] ?? '');
        $insert->execute([
            (int)$row['id'], 1, 0,
            gesture_package_canonical_json(gesture_package_legacy_manifest($row)),
            $animation !== '' ? 'legacy:' . $animation : null, 'image/gif',
            $poster !== '' ? 'legacy:' . $poster : null, null,
            $audio !== '' ? 'legacy:' . $audio : null, $audio !== '' ? 'audio/mpeg' : null,
            $token, 'legacy-toc-or-precanonical', 'legacy-unverified',
            (int)($row['uploaded_by_user_id'] ?: $row['owner_user_id']),
        ]);
        $update->execute([$token, (int)$row['id']]);
    }
}

function gesture_package_install_schema(PDO $pdo): void
{
    gesture_package_add_columns($pdo);
    gesture_package_create_tables($pdo);
    gesture_package_backfill($pdo);
    gesture_catalog_index($pdo, 'gesture_package_generations', 'idx_gesture_package_content', 'content_sha256');
    gesture_catalog_index($pdo, 'gesture_package_generations', 'idx_gesture_package_status', 'validation_status, created_at');
}

function gesture_package_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('gesture_package_canonicalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = gesture_package_canonicalize($item);
    return $value;
}

function gesture_package_canonical_json(array $value): string
{
    $json = json_encode(
        gesture_package_canonicalize($value),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($json)) throw new GestureCatalogException('Gesture package metadata could not be encoded.', 500, 'PACKAGE_METADATA_ENCODING_FAILED');
    return $json . "\n";
}

function gesture_package_storage_root(): string
{
    return security_private_storage_directory('gestures');
}

function gesture_package_staging_root(): string
{
    return security_private_storage_directory('gesture-staging');
}

function gesture_package_staging_directory(): string
{
    $directory = gesture_package_staging_root() . DIRECTORY_SEPARATOR . 'stage-' . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0770, false) && !is_dir($directory)) {
        throw new GestureCatalogException('Gesture staging could not be initialized.', 500, 'GESTURE_STAGING_FAILED');
    }
    return $directory;
}

function gesture_package_remove_tree(string $directory): void
{
    $root = realpath(gesture_package_staging_root());
    $target = realpath($directory);
    if ($root === false || $target === false || $target === $root || !str_starts_with(strtolower($target . DIRECTORY_SEPARATOR), strtolower($root . DIRECTORY_SEPARATOR))) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) @unlink($item->getPathname());
        elseif ($item->isDir()) @rmdir($item->getPathname());
    }
    @rmdir($target);
}

function gesture_package_safe_entry_name(string $name): string
{
    if (str_contains($name, "\0")) throw new GestureCatalogException('Gesture package contains an invalid entry name.', 400, 'ARCHIVE_ENTRY_NAME_INVALID');
    $normalized = str_replace('\\', '/', trim($name));
    if ($normalized === '' || strlen($normalized) > 180 || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)) {
        throw new GestureCatalogException('Gesture package contains an unsafe entry path.', 400, 'ARCHIVE_PATH_UNSAFE');
    }
    $segments = explode('/', $normalized);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
            throw new GestureCatalogException('Gesture package contains an unsafe entry path.', 400, 'ARCHIVE_PATH_UNSAFE');
        }
        $stem = strtoupper((string)preg_replace('/\..*$/', '', rtrim($segment, '. ')));
        if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $stem)) {
            throw new GestureCatalogException('Gesture package contains a reserved entry name.', 400, 'ARCHIVE_RESERVED_NAME');
        }
    }
    return implode('/', $segments);
}

function gesture_package_zip_entries(string $path): array
{
    if (!class_exists('ZipArchive')) throw new GestureCatalogException('PHP ZipArchive is required for gesture packages.', 503, 'ZIP_RUNTIME_REQUIRED');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new GestureCatalogException('Gesture package could not be opened.', 400, 'ARCHIVE_OPEN_FAILED');
    try {
        if ($zip->numFiles < 1 || $zip->numFiles > GESTURE_PACKAGE_MAX_ENTRIES) {
            throw new GestureCatalogException('Gesture package has too many or too few entries.', 400, 'ARCHIVE_ENTRY_LIMIT');
        }
        $entries = [];
        $seen = [];
        $expanded = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_array($stat)) throw new GestureCatalogException('Gesture package entry metadata is unreadable.', 400, 'ARCHIVE_ENTRY_INVALID');
            $rawName = (string)($stat['name'] ?? '');
            if (str_ends_with(str_replace('\\', '/', $rawName), '/')) continue;
            $name = gesture_package_safe_entry_name($rawName);
            $key = strtolower($name);
            if (isset($seen[$key])) throw new GestureCatalogException('Gesture package contains duplicate or case-colliding entries.', 400, 'ARCHIVE_ENTRY_COLLISION');
            $seen[$key] = true;
            $size = (int)($stat['size'] ?? 0);
            $compressed = (int)($stat['comp_size'] ?? 0);
            $method = (int)($stat['comp_method'] ?? -1);
            if (!in_array($method, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new GestureCatalogException('Gesture package uses an unsupported compression method.', 400, 'ARCHIVE_COMPRESSION_UNSUPPORTED');
            }
            if ($size < 0 || $compressed < 0 || $size > GESTURE_PACKAGE_MAX_ENTRY) {
                throw new GestureCatalogException('Gesture package entry is too large.', 400, 'ARCHIVE_ENTRY_TOO_LARGE');
            }
            if ($compressed === 0 && $size > 0 || ($compressed > 0 && $size / $compressed > GESTURE_PACKAGE_MAX_RATIO)) {
                throw new GestureCatalogException('Gesture package compression ratio is unsafe.', 400, 'ARCHIVE_RATIO_UNSAFE');
            }
            $expanded += $size;
            if ($expanded > GESTURE_PACKAGE_MAX_EXPANDED) throw new GestureCatalogException('Gesture package expands beyond the safe limit.', 400, 'ARCHIVE_EXPANDED_LIMIT');
            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                    $type = ($attributes >> 16) & 0170000;
                    if ($type !== 0 && $type !== 0100000) throw new GestureCatalogException('Gesture package links and special files are not allowed.', 400, 'ARCHIVE_SPECIAL_FILE');
                }
            }
            $bytes = $zip->getFromIndex($index, GESTURE_PACKAGE_MAX_ENTRY + 1, ZipArchive::FL_UNCHANGED);
            if (!is_string($bytes) || strlen($bytes) !== $size) throw new GestureCatalogException('Gesture package entry is truncated.', 400, 'ARCHIVE_ENTRY_TRUNCATED');
            $entries[$name] = $bytes;
        }
        return $entries;
    } finally {
        $zip->close();
    }
}

function gesture_package_find_entry(array $entries, string $wanted): ?string
{
    $wanted = str_replace('\\', '/', trim($wanted));
    if ($wanted === '') return null;
    foreach ($entries as $name => $_) {
        if (strcasecmp((string)$name, $wanted) === 0) return (string)$name;
    }
    return null;
}

function gesture_package_finfo_mime(string $bytes): string
{
    if (!class_exists('finfo')) throw new GestureCatalogException('PHP fileinfo is required for gesture media validation.', 503, 'FILEINFO_RUNTIME_REQUIRED');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return strtolower((string)$finfo->buffer($bytes));
}

function gesture_package_gif_metrics(string $bytes): array
{
    if (strlen($bytes) < 14 || !in_array(substr($bytes, 0, 6), ['GIF87a', 'GIF89a'], true)) {
        throw new GestureCatalogException('Gesture animation must be a valid GIF.', 400, 'ANIMATION_SIGNATURE_INVALID');
    }
    $dimensions = @getimagesizefromstring($bytes);
    if (!is_array($dimensions) || (int)($dimensions[2] ?? 0) !== IMAGETYPE_GIF) {
        throw new GestureCatalogException('Gesture animation could not be decoded as GIF.', 400, 'ANIMATION_DECODE_INVALID');
    }
    $width = (int)$dimensions[0];
    $height = (int)$dimensions[1];
    if ($width < 1 || $height < 1 || $width > GESTURE_MEDIA_MAX_DIMENSION || $height > GESTURE_MEDIA_MAX_DIMENSION || $width * $height > GESTURE_MEDIA_MAX_PIXELS) {
        throw new GestureCatalogException('Gesture animation dimensions exceed the safe limit.', 400, 'ANIMATION_DIMENSIONS_UNSAFE');
    }
    $offset = 13;
    $packed = ord($bytes[10]);
    if ($packed & 0x80) $offset += 3 * (2 << ($packed & 0x07));
    $length = strlen($bytes);
    $frames = 0;
    $duration = 0;
    $pendingDelay = 0;
    $skipSubBlocks = static function () use (&$bytes, &$offset, $length): void {
        while ($offset < $length) {
            $block = ord($bytes[$offset++]);
            if ($block === 0) return;
            if ($offset + $block > $length) throw new GestureCatalogException('Gesture GIF contains a truncated data block.', 400, 'ANIMATION_TRUNCATED');
            $offset += $block;
        }
        throw new GestureCatalogException('Gesture GIF is missing a data terminator.', 400, 'ANIMATION_TRUNCATED');
    };
    $trailer = false;
    while ($offset < $length) {
        $marker = ord($bytes[$offset++]);
        if ($marker === 0x3B) { $trailer = true; break; }
        if ($marker === 0x21) {
            if ($offset >= $length) break;
            $label = ord($bytes[$offset++]);
            if ($label === 0xF9) {
                if ($offset + 6 > $length || ord($bytes[$offset]) !== 4) throw new GestureCatalogException('Gesture GIF control data is malformed.', 400, 'ANIMATION_CONTROL_INVALID');
                $pendingDelay = unpack('v', substr($bytes, $offset + 2, 2))[1] * 10;
                $offset += 6;
            } else {
                $skipSubBlocks();
            }
            continue;
        }
        if ($marker !== 0x2C || $offset + 9 > $length) throw new GestureCatalogException('Gesture GIF block structure is invalid.', 400, 'ANIMATION_STRUCTURE_INVALID');
        $imagePacked = ord($bytes[$offset + 8]);
        $offset += 9;
        if ($imagePacked & 0x80) $offset += 3 * (2 << ($imagePacked & 0x07));
        if ($offset >= $length) throw new GestureCatalogException('Gesture GIF image data is truncated.', 400, 'ANIMATION_TRUNCATED');
        $offset++;
        $skipSubBlocks();
        $frames++;
        $duration += max(20, $pendingDelay);
        $pendingDelay = 0;
        if ($frames > GESTURE_ANIMATION_MAX_FRAMES || $duration > GESTURE_MEDIA_MAX_DURATION_MS) {
            throw new GestureCatalogException('Gesture animation exceeds the frame or duration limit.', 400, 'ANIMATION_RESOURCE_LIMIT');
        }
    }
    if (!$trailer || $frames < 1) throw new GestureCatalogException('Gesture GIF is incomplete.', 400, 'ANIMATION_TRUNCATED');
    return ['width' => $width, 'height' => $height, 'frames' => $frames, 'duration_ms' => $duration];
}

function gesture_package_validate_animation(string $bytes): array
{
    if ($bytes === '' || strlen($bytes) > GESTURE_ANIMATION_MAX_BYTES) throw new GestureCatalogException('Gesture animation is empty or too large.', 400, 'ANIMATION_SIZE_INVALID');
    if (gesture_package_finfo_mime($bytes) !== 'image/gif') throw new GestureCatalogException('Gesture animation type does not match GIF content.', 400, 'ANIMATION_MIME_MISMATCH');
    return ['bytes' => $bytes, 'mime' => 'image/gif', 'extension' => 'gif', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)] + gesture_package_gif_metrics($bytes);
}

function gesture_package_validate_poster(string $bytes, string $declaredName = ''): array
{
    if ($bytes === '' || strlen($bytes) > GESTURE_POSTER_MAX_BYTES) throw new GestureCatalogException('Gesture poster is empty or too large.', 400, 'POSTER_SIZE_INVALID');
    $mime = gesture_package_finfo_mime($bytes);
    $allow = ['image/gif' => ['gif', IMAGETYPE_GIF], 'image/png' => ['png', IMAGETYPE_PNG], 'image/jpeg' => ['jpg', IMAGETYPE_JPEG], 'image/webp' => ['webp', IMAGETYPE_WEBP]];
    if (!isset($allow[$mime])) throw new GestureCatalogException('Gesture poster format is unsupported.', 400, 'POSTER_MIME_UNSUPPORTED');
    $extension = strtolower(pathinfo($declaredName, PATHINFO_EXTENSION));
    if ($extension !== '' && !in_array($extension, [$allow[$mime][0], $mime === 'image/jpeg' ? 'jpeg' : $allow[$mime][0]], true)) {
        throw new GestureCatalogException('Gesture poster extension does not match its content.', 400, 'POSTER_MIME_MISMATCH');
    }
    $dimensions = @getimagesizefromstring($bytes);
    if (!is_array($dimensions) || (int)($dimensions[2] ?? 0) !== $allow[$mime][1]) throw new GestureCatalogException('Gesture poster could not be decoded.', 400, 'POSTER_DECODE_INVALID');
    $width = (int)$dimensions[0];
    $height = (int)$dimensions[1];
    if ($width < 1 || $height < 1 || $width > GESTURE_MEDIA_MAX_DIMENSION || $height > GESTURE_MEDIA_MAX_DIMENSION || $width * $height > GESTURE_MEDIA_MAX_PIXELS) {
        throw new GestureCatalogException('Gesture poster dimensions exceed the safe limit.', 400, 'POSTER_DIMENSIONS_UNSAFE');
    }
    return ['bytes' => $bytes, 'mime' => $mime, 'extension' => $allow[$mime][0], 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'width' => $width, 'height' => $height];
}

function gesture_package_mp3_metrics(string $bytes): array
{
    $length = strlen($bytes);
    $offset = 0;
    if (str_starts_with($bytes, 'ID3') && $length >= 10) {
        $offset = 10 + ((ord($bytes[6]) & 0x7f) << 21) + ((ord($bytes[7]) & 0x7f) << 14) + ((ord($bytes[8]) & 0x7f) << 7) + (ord($bytes[9]) & 0x7f);
    }
    $limit = min($length - 4, $offset + 65536);
    $bitrates = [1 => [1 => [32,40,48,56,64,80,96,112,128,160,192,224,256,320], 2 => [32,48,56,64,80,96,112,128,160,192,224,256,320,384]], 2 => [1 => [8,16,24,32,40,48,56,64,80,96,112,128,144,160], 2 => [8,16,24,32,40,48,56,64,80,96,112,128,144,160]]];
    for ($index = max(0, $offset); $index <= $limit; $index++) {
        $header = unpack('N', substr($bytes, $index, 4))[1];
        if (($header & 0xFFE00000) !== 0xFFE00000) continue;
        $versionBits = ($header >> 19) & 3;
        $layerBits = ($header >> 17) & 3;
        $bitrateIndex = ($header >> 12) & 15;
        $sampleIndex = ($header >> 10) & 3;
        if ($versionBits === 1 || $layerBits !== 1 || $bitrateIndex < 1 || $bitrateIndex > 14 || $sampleIndex > 2) continue;
        $versionGroup = $versionBits === 3 ? 1 : 2;
        $baseRates = [44100, 48000, 32000];
        $sampleRate = $baseRates[$sampleIndex] / ($versionBits === 3 ? 1 : ($versionBits === 2 ? 2 : 4));
        $bitrate = $bitrates[$versionGroup][1][$bitrateIndex - 1] * 1000;
        $channels = (($header >> 6) & 3) === 3 ? 1 : 2;
        $duration = (int)round(($length * 8 / max(1, $bitrate)) * 1000);
        if ($duration > GESTURE_MEDIA_MAX_DURATION_MS) throw new GestureCatalogException('Gesture audio exceeds the duration limit.', 400, 'AUDIO_DURATION_LIMIT');
        return ['duration_ms' => max(1, $duration), 'channels' => $channels, 'sample_rate' => (int)$sampleRate];
    }
    throw new GestureCatalogException('Gesture audio is not a readable MP3 stream.', 400, 'AUDIO_SIGNATURE_INVALID');
}

function gesture_package_validate_audio(string $bytes): array
{
    if ($bytes === '' || strlen($bytes) > GESTURE_AUDIO_MAX_BYTES) throw new GestureCatalogException('Gesture audio is empty or too large.', 400, 'AUDIO_SIZE_INVALID');
    $mime = gesture_package_finfo_mime($bytes);
    if (!in_array($mime, ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], true)) throw new GestureCatalogException('Gesture audio type does not match MP3 content.', 400, 'AUDIO_MIME_MISMATCH');
    return ['bytes' => $bytes, 'mime' => 'audio/mpeg', 'extension' => 'mp3', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)] + gesture_package_mp3_metrics($bytes);
}

function gesture_package_parse_archive(string $path): array
{
    if (!is_file($path) || filesize($path) < 1 || filesize($path) > GESTURE_PACKAGE_MAX_COMPRESSED) {
        throw new GestureCatalogException('Gesture package is empty or too large.', 400, 'PACKAGE_SIZE_INVALID');
    }
    $entries = gesture_package_zip_entries($path);
    $manifestName = gesture_package_find_entry($entries, 'manifest.json');
    $compatibility = 'canonical-v1';
    if ($manifestName === null) {
        $manifestName = gesture_package_find_entry($entries, 'toc.json') ?? gesture_package_find_entry($entries, 'meta.json');
        $compatibility = 'legacy-toc-or-meta';
    }
    if ($manifestName === null) throw new GestureCatalogException('Gesture package is missing manifest.json, toc.json, or meta.json.', 400, 'PACKAGE_MANIFEST_MISSING');
    $raw = $entries[$manifestName];
    if (strlen($raw) > GESTURE_PACKAGE_MAX_MANIFEST) throw new GestureCatalogException('Gesture package manifest is too large.', 400, 'PACKAGE_MANIFEST_LIMIT');
    $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) throw new GestureCatalogException('Gesture package manifest is invalid.', 400, 'PACKAGE_MANIFEST_INVALID');

    if ($compatibility === 'canonical-v1') {
        if (($manifest['schema'] ?? '') !== GESTURE_PACKAGE_SCHEMA || (int)($manifest['version'] ?? 0) !== GESTURE_PACKAGE_VERSION) {
            throw new GestureCatalogException('Gesture package version is unsupported.', 400, 'PACKAGE_VERSION_UNSUPPORTED');
        }
        $media = is_array($manifest['media'] ?? null) ? $manifest['media'] : [];
        $animationName = (string)($media['animation']['entry'] ?? '');
        $posterName = (string)($media['poster']['entry'] ?? '');
        $audioName = (string)($media['audio']['entry'] ?? '');
    } else {
        $animationName = (string)($manifest['animation'] ?? 'animation.gif');
        $posterName = (string)($manifest['poster'] ?? '');
        $audioName = (string)($manifest['audio'] ?? '');
    }
    $animationEntry = gesture_package_find_entry($entries, $animationName);
    if ($animationEntry === null) throw new GestureCatalogException('Gesture package is missing its animation entry.', 400, 'PACKAGE_ANIMATION_MISSING');
    $posterEntry = $posterName !== '' ? gesture_package_find_entry($entries, $posterName) : null;
    $audioEntry = $audioName !== '' ? gesture_package_find_entry($entries, $audioName) : null;
    $animation = gesture_package_validate_animation($entries[$animationEntry]);
    $poster = $posterEntry !== null ? gesture_package_validate_poster($entries[$posterEntry], $posterEntry) : null;
    $audio = $audioEntry !== null ? gesture_package_validate_audio($entries[$audioEntry]) : null;
    return [
        'manifest' => $manifest,
        'animation' => $animation,
        'poster' => $poster,
        'audio' => $audio,
        'compatibility' => $compatibility,
        'source_package_sha256' => hash_file('sha256', $path),
    ];
}

function gesture_package_file_descriptor(?array $file, string $label, int $maxBytes): ?array
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new GestureCatalogException("{$label} upload did not complete.", 400, 'MEDIA_UPLOAD_FAILED');
    $path = (string)($file['tmp_name'] ?? $file['path'] ?? '');
    $size = is_file($path) ? filesize($path) : false;
    if ($path === '' || $size === false || $size < 1 || $size > $maxBytes) throw new GestureCatalogException("{$label} is empty or too large.", 400, 'MEDIA_UPLOAD_SIZE_INVALID');
    return ['path' => $path, 'name' => basename((string)($file['name'] ?? $label)), 'size' => (int)$size, 'sha256' => hash_file('sha256', $path)];
}

function gesture_package_generation(PDO $pdo, int $gestureId, int $generation): ?array
{
    $sql = 'SELECT * FROM gesture_package_generations WHERE gesture_id = ? AND generation = ? LIMIT 1';
    if ($pdo->inTransaction() && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$gestureId, $generation]);
    return $stmt->fetch() ?: null;
}

function gesture_package_resolve_storage(?string $storageName): ?string
{
    if (!$storageName) return null;
    if (str_starts_with($storageName, 'legacy:')) {
        $public = substr($storageName, 7);
        if (!str_starts_with($public, '/assets/uploads/gestures/') || str_contains($public, '..')) return null;
        $path = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $public);
        return is_file($path) ? $path : null;
    }
    if (basename($storageName) !== $storageName || str_contains($storageName, '..')) return null;
    $path = gesture_package_storage_root() . DIRECTORY_SEPARATOR . $storageName;
    return is_file($path) ? $path : null;
}

function gesture_package_asset_bytes(?array $generation, string $role): ?string
{
    $name = (string)($generation[$role . '_storage_name'] ?? '');
    $path = gesture_package_resolve_storage($name);
    if ($path === null) return null;
    $limit = match ($role) {
        'animation' => GESTURE_ANIMATION_MAX_BYTES,
        'poster' => GESTURE_POSTER_MAX_BYTES,
        'audio' => GESTURE_AUDIO_MAX_BYTES,
        'package' => GESTURE_PACKAGE_MAX_COMPRESSED,
        default => 0,
    };
    if ($limit < 1 || filesize($path) > $limit) return null;
    $bytes = file_get_contents($path);
    return is_string($bytes) ? $bytes : null;
}

function gesture_package_prepare(array $files, array $fields, ?array $currentGeneration = null): array
{
    $packageFile = gesture_package_file_descriptor($files['package'] ?? null, 'AGST package', GESTURE_PACKAGE_MAX_COMPRESSED);
    $animationFile = gesture_package_file_descriptor($files['animation'] ?? null, 'Gesture animation', GESTURE_ANIMATION_MAX_BYTES);
    $posterFile = gesture_package_file_descriptor($files['poster'] ?? null, 'Gesture poster', GESTURE_POSTER_MAX_BYTES);
    $audioFile = gesture_package_file_descriptor($files['audio'] ?? null, 'Gesture audio', GESTURE_AUDIO_MAX_BYTES);
    $parsed = $packageFile ? gesture_package_parse_archive($packageFile['path']) : null;
    $manifest = is_array($parsed['manifest'] ?? null) ? $parsed['manifest'] : [];
    $animation = $parsed['animation'] ?? null;
    $poster = $parsed['poster'] ?? null;
    $audio = $parsed['audio'] ?? null;
    if ($animationFile) $animation = gesture_package_validate_animation((string)file_get_contents($animationFile['path']));
    if ($posterFile) $poster = gesture_package_validate_poster((string)file_get_contents($posterFile['path']), $posterFile['name']);
    if ($audioFile) $audio = gesture_package_validate_audio((string)file_get_contents($audioFile['path']));

    if ($animation === null && $currentGeneration) {
        $bytes = gesture_package_asset_bytes($currentGeneration, 'animation');
        if ($bytes !== null) $animation = gesture_package_validate_animation($bytes);
    }
    if ($poster === null && $currentGeneration && empty($fields['remove_poster'])) {
        $bytes = gesture_package_asset_bytes($currentGeneration, 'poster');
        if ($bytes !== null) $poster = gesture_package_validate_poster($bytes, (string)$currentGeneration['poster_storage_name']);
    }
    if ($audio === null && $currentGeneration && empty($fields['remove_audio'])) {
        $bytes = gesture_package_asset_bytes($currentGeneration, 'audio');
        if ($bytes !== null) $audio = gesture_package_validate_audio($bytes);
    }
    if ($animation === null) throw new GestureCatalogException('A validated GIF animation or AGST package is required.', 400, 'ANIMATION_REQUIRED');

    $legacyAuthor = (string)($manifest['creator_credit'] ?? $manifest['author'] ?? '');
    $legacyText = (string)($manifest['text'] ?? $manifest['fallbackText'] ?? $manifest['gestureText'] ?? '');
    $title = gesture_catalog_clean_text((string)($fields['title'] ?? $manifest['title'] ?? $manifest['name'] ?? ''), 120, 'Gesture');
    $text = gesture_catalog_clean_text((string)($fields['text'] ?? $legacyText), 180, $title);
    $creator = gesture_catalog_clean_text((string)($fields['creator_credit'] ?? $legacyAuthor), 120, 'Unknown creator');
    $catalog = gesture_catalog_filename_stem((string)($fields['catalog_filename'] ?? $title), 'gesture');
    return [
        'metadata' => ['title' => $title, 'text' => $text, 'creator_credit' => $creator, 'catalog_filename' => $catalog],
        'animation' => $animation,
        'poster' => $poster,
        'audio' => $audio,
        'compatibility' => $parsed['compatibility'] ?? ($currentGeneration ? (string)$currentGeneration['compatibility'] : 'native-v1'),
        'source_package_sha256' => $parsed['source_package_sha256'] ?? null,
        'request_fingerprint' => hash('sha256', gesture_package_canonical_json([
            'metadata' => ['title' => $title, 'text' => $text, 'creator_credit' => $creator, 'catalog_filename' => $catalog],
            'animation' => $animation['sha256'],
            'poster' => $poster['sha256'] ?? null,
            'audio' => $audio['sha256'] ?? null,
            'source_package' => $parsed['source_package_sha256'] ?? null,
        ])),
    ];
}

function gesture_package_upload_present(array $files, string $role): bool
{
    $file = $files[$role] ?? null;
    return is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
}

function gesture_package_enforce_feature_policy(PDO $pdo, array $prepared, array $files, bool $creating, bool $admin): void
{
    $features = gesture_part4_feature_flags($pdo);
    $packageUploaded = gesture_package_upload_present($files, 'package');
    $animationUploaded = gesture_package_upload_present($files, 'animation');
    $posterUploaded = gesture_package_upload_present($files, 'poster');
    $audioUploaded = gesture_package_upload_present($files, 'audio');
    if (!$admin) gesture_catalog_require_user_mutation($pdo);
    if (!$admin && empty($features['editor'])) {
        throw new GestureCatalogException('Gesture Maker and editing are disabled through shared Settings.', 403, 'GESTURE_PART4_FEATURE_DISABLED');
    }
    if (!$admin && $packageUploaded && empty($features['user_package_import'])) {
        throw new GestureCatalogException('Gesture package import is disabled through shared Settings.', 403, 'GESTURE_PART4_FEATURE_DISABLED');
    }
    if ($admin && empty($features['admin_media_replacement'])) {
        throw new GestureCatalogException('Admin gesture media replacement is disabled through shared Settings.', 403, 'GESTURE_PART4_FEATURE_DISABLED');
    }
    if (($creating || $packageUploaded || $animationUploaded || $posterUploaded) && empty($features['animation_media'])) {
        throw new GestureCatalogException('Gesture animation media is disabled through shared Settings.', 403, 'GESTURE_PART4_FEATURE_DISABLED');
    }
    if (($audioUploaded || ($packageUploaded && !empty($prepared['audio']))) && empty($features['audio_media'])) {
        throw new GestureCatalogException('Gesture audio media is disabled through shared Settings.', 403, 'GESTURE_PART4_FEATURE_DISABLED');
    }
    if (str_contains(strtolower((string)$prepared['compatibility']), 'legacy') && empty($features['legacy_agst'])) {
        throw new GestureCatalogException('Legacy AGST compatibility is disabled through shared Settings.', 403, 'GESTURE_PART4_FEATURE_DISABLED');
    }
}

function gesture_package_manifest(string $publicId, int $generation, array $prepared): array
{
    $metadata = $prepared['metadata'];
    $media = [
        'animation' => array_filter([
            'entry' => 'media/animation.gif', 'mime' => 'image/gif', 'sha256' => $prepared['animation']['sha256'],
            'bytes' => $prepared['animation']['size'], 'width' => $prepared['animation']['width'], 'height' => $prepared['animation']['height'],
            'frames' => $prepared['animation']['frames'], 'duration_ms' => $prepared['animation']['duration_ms'],
        ], static fn(mixed $value): bool => $value !== null),
    ];
    if ($prepared['poster']) {
        $media['poster'] = [
            'entry' => 'media/poster.' . $prepared['poster']['extension'], 'mime' => $prepared['poster']['mime'],
            'sha256' => $prepared['poster']['sha256'], 'bytes' => $prepared['poster']['size'],
            'width' => $prepared['poster']['width'], 'height' => $prepared['poster']['height'],
        ];
    }
    if ($prepared['audio']) {
        $media['audio'] = [
            'entry' => 'media/audio.mp3', 'mime' => 'audio/mpeg', 'sha256' => $prepared['audio']['sha256'],
            'bytes' => $prepared['audio']['size'], 'duration_ms' => $prepared['audio']['duration_ms'],
            'channels' => $prepared['audio']['channels'], 'sample_rate' => $prepared['audio']['sample_rate'],
        ];
    }
    $content = [
        'schema' => GESTURE_PACKAGE_SCHEMA,
        'version' => GESTURE_PACKAGE_VERSION,
        'id' => $publicId,
        'generation' => $generation,
        'title' => $metadata['title'],
        'text' => $metadata['text'],
        'creator_credit' => $metadata['creator_credit'],
        'media' => $media,
        'compatibility' => $prepared['compatibility'],
    ];
    $content['content_sha256'] = hash('sha256', gesture_package_canonical_json($content));
    return $content;
}

function gesture_package_write_zip(string $path, array $entries): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new GestureCatalogException('Gesture package could not be created.', 500, 'PACKAGE_WRITE_FAILED');
    try {
        ksort($entries, SORT_STRING);
        foreach ($entries as $name => $bytes) {
            if (!$zip->addFromString($name, $bytes)) throw new GestureCatalogException('Gesture package entry could not be written.', 500, 'PACKAGE_WRITE_FAILED');
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
            if (method_exists($zip, 'setMtimeName')) $zip->setMtimeName($name, 315532800);
        }
        $zip->setArchiveComment('');
    } finally {
        $zip->close();
    }
}

function gesture_package_promote(string $publicId, int $generation, array $prepared): array
{
    $stage = gesture_package_staging_directory();
    $promoted = [];
    try {
        $manifest = gesture_package_manifest($publicId, $generation, $prepared);
        $manifestJson = gesture_package_canonical_json($manifest);
        $prefix = strtolower($publicId) . '.g' . $generation;
        $names = [
            'package' => $prefix . '.agst',
            'animation' => $prefix . '.animation.gif',
            'poster' => $prepared['poster'] ? $prefix . '.poster.' . $prepared['poster']['extension'] : null,
            'audio' => $prepared['audio'] ? $prefix . '.audio.mp3' : null,
        ];
        $entries = ['manifest.json' => $manifestJson, 'media/animation.gif' => $prepared['animation']['bytes']];
        if ($prepared['poster']) $entries['media/poster.' . $prepared['poster']['extension']] = $prepared['poster']['bytes'];
        if ($prepared['audio']) $entries['media/audio.mp3'] = $prepared['audio']['bytes'];
        $staged = [
            'animation' => $stage . DIRECTORY_SEPARATOR . $names['animation'],
            'package' => $stage . DIRECTORY_SEPARATOR . $names['package'],
        ];
        file_put_contents($staged['animation'], $prepared['animation']['bytes'], LOCK_EX);
        if ($prepared['poster']) {
            $staged['poster'] = $stage . DIRECTORY_SEPARATOR . $names['poster'];
            file_put_contents($staged['poster'], $prepared['poster']['bytes'], LOCK_EX);
        }
        if ($prepared['audio']) {
            $staged['audio'] = $stage . DIRECTORY_SEPARATOR . $names['audio'];
            file_put_contents($staged['audio'], $prepared['audio']['bytes'], LOCK_EX);
        }
        gesture_package_write_zip($staged['package'], $entries);
        $root = gesture_package_storage_root();
        foreach ($staged as $role => $source) {
            $destination = $root . DIRECTORY_SEPARATOR . $names[$role];
            if (is_file($destination) || !rename($source, $destination)) throw new GestureCatalogException('Gesture package storage promotion failed.', 500, 'PACKAGE_PROMOTION_FAILED');
            $promoted[] = $destination;
        }
        return [
            'manifest' => $manifest,
            'manifest_json' => $manifestJson,
            'names' => $names,
            'package_sha256' => hash_file('sha256', $root . DIRECTORY_SEPARATOR . $names['package']),
            'content_sha256' => $manifest['content_sha256'],
            'promoted' => $promoted,
        ];
    } catch (Throwable $error) {
        foreach ($promoted as $path) @unlink($path);
        throw $error;
    } finally {
        gesture_package_remove_tree($stage);
    }
}

function gesture_package_cleanup_promoted(array $paths): void
{
    $root = realpath(gesture_package_storage_root());
    if ($root === false) return;
    foreach ($paths as $path) {
        $directory = realpath(dirname((string)$path));
        if ($directory !== false && hash_equals(strtolower($root), strtolower($directory)) && is_file($path)) @unlink($path);
    }
}

function gesture_package_insert_generation(PDO $pdo, int $gestureId, int $generation, int $actorId, string $token, array $prepared, array $bundle): void
{
    $animation = $prepared['animation'];
    $poster = $prepared['poster'];
    $audio = $prepared['audio'];
    $pdo->prepare(
        'INSERT INTO gesture_package_generations '
        . '(gesture_id,generation,package_version,manifest_json,package_storage_name,animation_storage_name,animation_mime,animation_size,animation_width,animation_height,animation_frames,animation_duration_ms,poster_storage_name,poster_mime,poster_size,poster_width,poster_height,audio_storage_name,audio_mime,audio_size,audio_duration_ms,audio_channels,audio_sample_rate,content_sha256,package_sha256,media_access_token,compatibility,validation_status,created_by_user_id) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $gestureId, $generation, GESTURE_PACKAGE_VERSION, $bundle['manifest_json'], $bundle['names']['package'],
        $bundle['names']['animation'], $animation['mime'], $animation['size'], $animation['width'], $animation['height'], $animation['frames'], $animation['duration_ms'],
        $bundle['names']['poster'], $poster['mime'] ?? null, $poster['size'] ?? 0, $poster['width'] ?? null, $poster['height'] ?? null,
        $bundle['names']['audio'], $audio['mime'] ?? null, $audio['size'] ?? 0, $audio['duration_ms'] ?? null, $audio['channels'] ?? null, $audio['sample_rate'] ?? null,
        $bundle['content_sha256'], $bundle['package_sha256'], $token, $prepared['compatibility'], 'valid', $actorId,
    ]);
}

function gesture_package_create(PDO $pdo, array $actor, array $fields, array $files, string $requestKey): array
{
    $actorId = (int)($actor['id'] ?? 0);
    if ($actorId < 1) throw new GestureCatalogException('Authentication is required.', 401, 'AUTHENTICATION_REQUIRED');
    $capability = gesture_catalog_require_user_mutation($pdo);
    gesture_capability_require_scope($capability, 'personal');
    $prepared = gesture_package_prepare($files, $fields);
    gesture_package_enforce_feature_policy($pdo, $prepared, $files, true, false);
    $promoted = [];
    try {
        return gesture_catalog_idempotent($pdo, $actorId, 'part4-create', $requestKey, ['fingerprint' => $prepared['request_fingerprint']], function () use ($pdo, $actor, $actorId, $prepared, &$promoted): array {
            gesture_catalog_lock_user($pdo, $actorId);
            $capability = gesture_catalog_require_user_mutation($pdo, true);
            gesture_capability_require_scope($capability, 'personal');
            $limit = max(1, (int)app_setting($pdo, 'gesture_upload_limit', '50'));
            $count = $pdo->prepare('SELECT COUNT(*) FROM gestures WHERE owner_user_id = ? AND deleted_at IS NULL');
            $count->execute([$actorId]);
            if ((int)$count->fetchColumn() >= $limit) throw new GestureCatalogException('Gesture limit reached. Remove some gestures to make room.', 409, 'GESTURE_LIMIT_REACHED');
            $publicId = uuid_v4();
            $generation = 1;
            $metadata = $prepared['metadata'];
            gesture_catalog_assert_filename_available($pdo, $actorId, $metadata['catalog_filename']);
            $bundle = gesture_package_promote($publicId, $generation, $prepared);
            $promoted = $bundle['promoted'];
            $token = bin2hex(random_bytes(24));
            $now = gmdate('Y-m-d H:i:s');
            $pdo->prepare(
                'INSERT INTO gestures (public_id,owner_user_id,name,gesture_text,gif_path,audio_path,audio_is_silent,is_public,file_size,original_filename,catalog_filename,catalog_filename_key,active_catalog_key,title,creator_credit,uploaded_by_user_id,original_uploaded_at,content_updated_at,metadata_updated_at,visibility_changed_at,version,legacy_metadata,package_generation,package_has_poster,package_status,package_version,package_sha256,content_sha256,media_access_token,package_updated_at) '
                . "VALUES (?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?,?,1,0,?,?,'valid',?,?,?,?,?)"
            )->execute([
                $publicId, $actorId, $metadata['title'], $metadata['text'], 'protected:animation', $prepared['audio'] ? 'protected:audio' : null,
                $prepared['audio'] ? 0 : 1, $prepared['animation']['size'] + ($prepared['poster']['size'] ?? 0) + ($prepared['audio']['size'] ?? 0),
                $metadata['catalog_filename'] . '.agst', $metadata['catalog_filename'], gesture_catalog_filename_key($metadata['catalog_filename']), 'active',
                $metadata['title'], $metadata['creator_credit'], $actorId, $now, $now, $now, $now,
                $generation, $prepared['poster'] ? 1 : 0, GESTURE_PACKAGE_VERSION, $bundle['package_sha256'], $bundle['content_sha256'], $token, $now,
            ]);
            $gestureId = (int)$pdo->lastInsertId();
            gesture_package_insert_generation($pdo, $gestureId, $generation, $actorId, $token, $prepared, $bundle);
            log_tool($pdo, $actorId, 'gesture_part4_create', $actorId, null, json_encode(['gesture_public_id' => $publicId, 'generation' => $generation, 'package_version' => GESTURE_PACKAGE_VERSION, 'content_sha256' => $bundle['content_sha256']], JSON_UNESCAPED_SLASHES));
            $row = gesture_catalog_lock_row($pdo, $publicId, $actorId);
            return [
                'ok' => true,
                'gesture' => gesture_capability_project_catalog_payload(
                    $pdo,
                    gesture_catalog_row_payload($row, $actorId)
                ),
                'package' => gesture_package_public_summary($pdo, $row, false),
            ];
        });
    } catch (Throwable $error) {
        gesture_package_cleanup_promoted($promoted);
        throw $error;
    }
}

function gesture_package_edit(PDO $pdo, array $actor, string $publicId, array $fields, array $files, int $expectedVersion, string $requestKey, bool $admin = false): array
{
    $actorId = (int)($actor['id'] ?? 0);
    if ($actorId < 1) throw new GestureCatalogException('Authentication is required.', 401, 'AUTHENTICATION_REQUIRED');
    if (!$admin) {
        $capability = gesture_catalog_require_user_mutation($pdo);
        gesture_capability_require_scope($capability, 'personal');
    }
    $probe = gesture_catalog_lock_row($pdo, $publicId, $admin ? null : $actorId);
    if ($admin && ($actor['role'] ?? '') !== 'admin') throw new GestureCatalogException('Administrator authorization is required.', 403, 'ADMIN_REQUIRED');
    if ($admin && empty($probe['is_public'])) throw new GestureCatalogException('Admin package editing is limited to Server Gestures.', 409, 'SERVER_GESTURE_REQUIRED');
    $current = gesture_package_generation($pdo, (int)$probe['id'], max(1, (int)$probe['package_generation']));
    $prepared = gesture_package_prepare($files, $fields + [
        'title' => $probe['title'], 'text' => $probe['gesture_text'], 'creator_credit' => $probe['creator_credit'], 'catalog_filename' => $probe['catalog_filename'],
    ], $current);
    gesture_package_enforce_feature_policy($pdo, $prepared, $files, false, $admin);
    $promoted = [];
    try {
        return gesture_catalog_idempotent($pdo, $actorId, $admin ? 'part4-admin-edit' : 'part4-owner-edit', $requestKey, ['public_id' => $publicId, 'expected_version' => $expectedVersion, 'fingerprint' => $prepared['request_fingerprint']], function () use ($pdo, $actor, $actorId, $admin, $publicId, $expectedVersion, $prepared, &$promoted): array {
            if (!$admin) {
                $capability = gesture_catalog_require_user_mutation($pdo, true);
                gesture_capability_require_scope($capability, 'personal');
            }
            $row = gesture_catalog_lock_row($pdo, $publicId, $admin ? null : $actorId);
            if ($admin && (empty($row['is_public']) || ($actor['role'] ?? '') !== 'admin')) throw new GestureCatalogException('Admin package editing is not authorized.', 403, 'ADMIN_EDIT_NOT_AUTHORIZED');
            gesture_catalog_require_version((int)$row['version'], $expectedVersion, 'GESTURE_VERSION_CONFLICT', gesture_catalog_row_payload($row, $actorId, $admin));
            $metadata = $prepared['metadata'];
            gesture_catalog_assert_filename_available($pdo, (int)$row['owner_user_id'], $metadata['catalog_filename'], (int)$row['id']);
            $generation = max(1, (int)$row['package_generation']) + 1;
            $bundle = gesture_package_promote($publicId, $generation, $prepared);
            $promoted = $bundle['promoted'];
            $token = bin2hex(random_bytes(24));
            $now = gmdate('Y-m-d H:i:s');
            $pdo->prepare(
                "UPDATE gestures SET name=?, gesture_text=?, gif_path='protected:animation', audio_path=?, audio_is_silent=?, file_size=?, catalog_filename=?, catalog_filename_key=?, title=?, creator_credit=?, content_updated_at=?, metadata_updated_at=?, updated_at=?, version=version+1, package_generation=?, package_has_poster=?, package_status='valid', package_version=?, package_sha256=?, content_sha256=?, media_access_token=?, package_updated_at=? WHERE id=?"
            )->execute([
                $metadata['title'], $metadata['text'], $prepared['audio'] ? 'protected:audio' : null, $prepared['audio'] ? 0 : 1,
                $prepared['animation']['size'] + ($prepared['poster']['size'] ?? 0) + ($prepared['audio']['size'] ?? 0),
                $metadata['catalog_filename'], gesture_catalog_filename_key($metadata['catalog_filename']), $metadata['title'], $metadata['creator_credit'],
                $now, $now, $now, $generation, $prepared['poster'] ? 1 : 0, GESTURE_PACKAGE_VERSION, $bundle['package_sha256'], $bundle['content_sha256'], $token, $now, (int)$row['id'],
            ]);
            gesture_package_insert_generation($pdo, (int)$row['id'], $generation, $actorId, $token, $prepared, $bundle);
            log_tool($pdo, $actorId, $admin ? 'gesture_part4_admin_edit' : 'gesture_part4_owner_edit', (int)$row['owner_user_id'], null, json_encode(['gesture_public_id' => $publicId, 'generation' => $generation, 'package_version' => GESTURE_PACKAGE_VERSION, 'content_sha256' => $bundle['content_sha256']], JSON_UNESCAPED_SLASHES));
            $updated = gesture_catalog_lock_row($pdo, $publicId, $admin ? null : $actorId);
            return [
                'ok' => true,
                'gesture' => gesture_capability_project_catalog_payload(
                    $pdo,
                    gesture_catalog_row_payload($updated, $actorId, $admin),
                    $admin
                ),
                'package' => gesture_package_public_summary($pdo, $updated, $admin),
            ];
        });
    } catch (Throwable $error) {
        gesture_package_cleanup_promoted($promoted);
        throw $error;
    }
}

function gesture_package_public_summary(PDO $pdo, array $gesture, bool $admin = false): array
{
    $generation = gesture_package_generation($pdo, (int)$gesture['id'], max(1, (int)$gesture['package_generation']));
    if (!$generation) return ['status' => 'missing', 'version' => 0, 'generation' => 0, 'media' => []];
    $media = [];
    foreach (['animation', 'poster', 'audio'] as $role) {
        if (empty($generation[$role . '_storage_name'])) continue;
        $media[$role] = array_filter([
            'mime' => $generation[$role . '_mime'],
            'bytes' => (int)$generation[$role . '_size'],
            'width' => isset($generation[$role . '_width']) ? (int)$generation[$role . '_width'] : null,
            'height' => isset($generation[$role . '_height']) ? (int)$generation[$role . '_height'] : null,
            'duration_ms' => isset($generation[$role . '_duration_ms']) ? (int)$generation[$role . '_duration_ms'] : null,
            'frames' => isset($generation[$role . '_frames']) ? (int)$generation[$role . '_frames'] : null,
        ], static fn(mixed $value): bool => $value !== null);
    }
    $summary = [
        'status' => (string)$generation['validation_status'],
        'version' => (int)$generation['package_version'],
        'generation' => (int)$generation['generation'],
        'compatibility' => (string)$generation['compatibility'],
        'content_sha256' => (string)($generation['content_sha256'] ?? ''),
        'media' => $media,
    ];
    if ($admin) $summary['package_sha256'] = (string)($generation['package_sha256'] ?? '');
    return $summary;
}

function gesture_package_editor_detail(PDO $pdo, array $actor, string $publicId, bool $admin = false): array
{
    $actorId = (int)($actor['id'] ?? 0);
    $features = gesture_part4_feature_flags($pdo);
    if (($admin && empty($features['admin_package_inspection'])) || (!$admin && empty($features['editor']))) {
        throw new GestureCatalogException('Gesture package inspection is disabled through shared Settings.', 403, 'GESTURE_PART4_FEATURE_DISABLED');
    }
    if (!$admin) {
        $capability = gesture_catalog_require_user_mutation($pdo);
        gesture_capability_require_scope($capability, 'personal');
    }
    $row = gesture_catalog_lock_row($pdo, $publicId, $admin ? null : $actorId);
    if ($admin && (($actor['role'] ?? '') !== 'admin' || empty($row['is_public']))) throw new GestureCatalogException('Admin package inspection is not authorized.', 403, 'ADMIN_INSPECTION_NOT_AUTHORIZED');
    $uploader = $pdo->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
    $uploader->execute([(int)($row['uploaded_by_user_id'] ?: $row['owner_user_id'])]);
    $row['uploader_display_name'] = (string)($uploader->fetchColumn() ?: '');
    $payload = gesture_capability_project_catalog_payload(
        $pdo,
        gesture_catalog_row_payload($row, $actorId, $admin),
        $admin,
        null,
        $features
    );
    return ['gesture' => $payload, 'package' => gesture_package_public_summary($pdo, $row, $admin), 'preferences' => gesture_catalog_preferences_payload($pdo, $actorId)];
}

function gesture_package_media_url(array $row, string $role, string $purpose = 'catalog'): ?string
{
    if (!in_array($role, ['animation', 'poster', 'audio'], true)) return null;
    if ((string)($row['package_status'] ?? 'legacy-unverified') === 'legacy-unverified') {
        if ($role === 'poster') return null;
        $legacyPath = (string)($row[$role === 'animation' ? 'gif_path' : 'audio_path'] ?? '');
        if ($legacyPath === '') return null;
        if (
            !empty($row['public_id'])
            && (int)($row['package_generation'] ?? 0) > 0
            && !empty($row['media_access_token'])
        ) {
            $publicId = rawurlencode((string)$row['public_id']);
            $generation = max(1, (int)$row['package_generation']);
            $token = rawurlencode((string)$row['media_access_token']);
            return app_url("/api/gesture_media.php?id={$publicId}&generation={$generation}&role={$role}&purpose=" . rawurlencode($purpose) . "&token={$token}");
        }
        return media_url($legacyPath);
    }
    if ($role === 'audio' && empty($row['audio_path'])) return null;
    if ($role === 'poster' && empty($row['package_has_poster'])) return null;
    $publicId = rawurlencode((string)$row['public_id']);
    $generation = max(1, (int)($row['package_generation'] ?? 1));
    $token = rawurlencode((string)($row['media_access_token'] ?? ''));
    return app_url("/api/gesture_media.php?id={$publicId}&generation={$generation}&role={$role}&purpose=" . rawurlencode($purpose) . "&token={$token}");
}

function gesture_package_media_record(PDO $pdo, string $publicId, int $generation): array
{
    $sql = 'SELECT g.*, pg.* , g.id AS gesture_id, pg.id AS package_generation_id '
        . 'FROM gestures g JOIN gesture_package_generations pg '
        . 'ON pg.gesture_id = g.id AND pg.generation = ? '
        . 'WHERE g.public_id = ? LIMIT 1';
    if ($pdo->inTransaction() && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$generation, $publicId]);
    $row = $stmt->fetch();
    if (!$row || !empty($row['deleted_at'])) throw new GestureCatalogException('Gesture media is unavailable.', 404, 'GESTURE_MEDIA_UNAVAILABLE');
    return $row;
}

function gesture_package_authorize_media(PDO $pdo, array $actor, array $record, string $token, string $role, string $purpose): void
{
    $actorId = (int)($actor['id'] ?? 0);
    $staff = in_array((string)($actor['role'] ?? ''), ['admin', 'developer'], true);
    $owner = $actorId === (int)$record['owner_user_id'];
    $public = !empty($record['is_public']);
    $capability = $token !== '' && hash_equals((string)$record['media_access_token'], $token);
    if ($purpose === 'admin' && (string)($actor['role'] ?? '') !== 'admin') {
        throw new GestureCatalogException(
            'Administrator media maintenance is not authorized.',
            403,
            'ADMIN_MEDIA_NOT_AUTHORIZED'
        );
    }
    if (!$capability || (!$staff && !$owner && !$public)) throw new GestureCatalogException('Gesture media access is not authorized.', 403, 'GESTURE_MEDIA_NOT_AUTHORIZED');
    $adminMaintenance = (string)($actor['role'] ?? '') === 'admin' && $purpose === 'admin';
    if (!$adminMaintenance) {
        $policy = gesture_capability_policy($pdo);
        gesture_capability_require_scope(
            $policy,
            gesture_capability_scope_for_gesture($record, $actorId)
        );
        $features = gesture_part4_feature_flags($pdo);
        if (in_array($role, ['animation', 'poster'], true) && empty($features['animation_media'])) {
            throw new GestureCatalogException(
                'Gesture animation media is disabled through shared Settings.',
                403,
                'GESTURE_PART4_FEATURE_DISABLED'
            );
        }
        if ($role === 'audio') {
            gesture_capability_require($policy, 'allow_gesture_audio_delivery');
            if (empty($features['audio_media'])) {
                throw new GestureCatalogException(
                    'Gesture audio media is disabled through shared Settings.',
                    403,
                    'GESTURE_PART4_FEATURE_DISABLED'
                );
            }
        }
    }
    if ($purpose === 'message') {
        $preferences = gesture_catalog_preferences_payload($pdo, $actorId);
        if ($role === 'animation' && empty($preferences['show_animations'])) throw new GestureCatalogException('Gesture animation delivery is disabled for this account.', 404, 'ANIMATION_DELIVERY_DISABLED');
        if ($role === 'audio' && empty($preferences['play_sounds'])) throw new GestureCatalogException('Gesture sound delivery is disabled for this account.', 404, 'AUDIO_DELIVERY_DISABLED');
    }
}

function gesture_package_download_record(PDO $pdo, array $actor, string $publicId): array
{
    $row = gesture_catalog_lock_row($pdo, $publicId);
    $actorId = (int)($actor['id'] ?? 0);
    $admin = ($actor['role'] ?? '') === 'admin';
    $owner = $actorId === (int)$row['owner_user_id'];
    $features = gesture_part4_feature_flags($pdo);
    if (($admin && empty($features['admin_package_inspection'])) || (!$admin && empty($features['user_package_download']))) {
        throw new GestureCatalogException('Gesture package download is disabled through shared Settings.', 403, 'GESTURE_PART4_FEATURE_DISABLED');
    }
    $policy = null;
    if (!$admin) {
        $policy = gesture_capability_policy($pdo);
        gesture_capability_require_scope(
            $policy,
            gesture_capability_scope_for_gesture($row, $actorId)
        );
    }
    $publicPolicy = !empty($features['user_package_download']);
    if (!$owner && !$admin && (empty($row['is_public']) || !$publicPolicy)) throw new GestureCatalogException('Gesture package download is not authorized.', 403, 'DOWNLOAD_NOT_AUTHORIZED');
    $generation = gesture_package_generation($pdo, (int)$row['id'], max(1, (int)$row['package_generation']));
    if (!$generation) throw new GestureCatalogException('Gesture package is unavailable.', 404, 'PACKAGE_UNAVAILABLE');
    if (!$admin && !empty($generation['audio_storage_name'])) {
        gesture_capability_require($policy, 'allow_gesture_audio_delivery');
    }
    return ['gesture' => $row, 'generation' => $generation, 'owner' => $owner, 'admin' => $admin];
}

function gesture_package_download_path(array $record): ?string
{
    return gesture_package_resolve_storage((string)($record['generation']['package_storage_name'] ?? ''));
}

function gesture_package_ephemeral_legacy_download(array $record): array
{
    $gesture = $record['gesture'];
    $generation = $record['generation'];
    $animationBytes = gesture_package_asset_bytes($generation, 'animation');
    if ($animationBytes === null) throw new GestureCatalogException('Legacy gesture animation is unavailable.', 404, 'LEGACY_ANIMATION_UNAVAILABLE');
    $prepared = [
        'metadata' => [
            'title' => gesture_catalog_clean_text((string)($gesture['title'] ?: $gesture['name']), 120, 'Gesture'),
            'text' => gesture_catalog_clean_text((string)$gesture['gesture_text'], 180, 'Gesture'),
            'creator_credit' => gesture_catalog_clean_text((string)($gesture['creator_credit'] ?: 'Unknown creator'), 120, 'Unknown creator'),
            'catalog_filename' => gesture_catalog_filename_stem((string)($gesture['catalog_filename'] ?: $gesture['name']), 'gesture'),
        ],
        'animation' => gesture_package_validate_animation($animationBytes),
        'poster' => null,
        'audio' => null,
        'compatibility' => 'legacy-toc-or-precanonical',
    ];
    $audioBytes = gesture_package_asset_bytes($generation, 'audio');
    if ($audioBytes !== null) $prepared['audio'] = gesture_package_validate_audio($audioBytes);
    $manifest = gesture_package_manifest((string)$gesture['public_id'], max(1, (int)$generation['generation']), $prepared);
    $directory = gesture_package_staging_directory();
    $path = $directory . DIRECTORY_SEPARATOR . 'gesture.agst';
    $entries = ['manifest.json' => gesture_package_canonical_json($manifest), 'media/animation.gif' => $prepared['animation']['bytes']];
    if ($prepared['audio']) $entries['media/audio.mp3'] = $prepared['audio']['bytes'];
    gesture_package_write_zip($path, $entries);
    return ['path' => $path, 'cleanup_directory' => $directory, 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)];
}

function gesture_package_cleanup_deleted(PDO $pdo, string $publicId): void
{
    $stmt = $pdo->prepare('SELECT pg.* FROM gesture_package_generations pg JOIN gestures g ON g.id = pg.gesture_id WHERE g.public_id = ? AND g.deleted_at IS NOT NULL');
    $stmt->execute([$publicId]);
    $rows = $stmt->fetchAll();
    $root = realpath(gesture_package_storage_root());
    foreach ($rows as $row) {
        foreach (['package', 'animation', 'poster', 'audio'] as $role) {
            $name = (string)($row[$role . '_storage_name'] ?? '');
            if ($name === '' || str_starts_with($name, 'legacy:') || basename($name) !== $name) continue;
            $path = gesture_package_storage_root() . DIRECTORY_SEPARATOR . $name;
            if ($root !== false && is_file($path) && hash_equals(strtolower($root), strtolower((string)realpath(dirname($path))))) @unlink($path);
        }
    }
    $pdo->prepare("UPDATE gesture_package_generations SET package_storage_name=NULL, animation_storage_name=NULL, poster_storage_name=NULL, audio_storage_name=NULL, validation_status='deleted' WHERE gesture_id=(SELECT id FROM gestures WHERE public_id=? LIMIT 1)")->execute([$publicId]);
}

function gesture_package_rotate_media_token(PDO $pdo, int $gestureId, int $generation): string
{
    $token = bin2hex(random_bytes(24));
    $pdo->prepare('UPDATE gestures SET media_access_token = ? WHERE id = ?')->execute([$token, $gestureId]);
    $pdo->prepare('UPDATE gesture_package_generations SET media_access_token = ? WHERE gesture_id = ? AND generation = ?')->execute([$token, $gestureId, $generation]);
    return $token;
}
