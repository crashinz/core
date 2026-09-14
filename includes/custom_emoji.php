<?php
declare(strict_types=1);

const CUSTOM_EMOJI_MAX_BYTES = 5 * 1024 * 1024;
const CUSTOM_EMOJI_MAX_WIDTH = 512;
const CUSTOM_EMOJI_MAX_HEIGHT = 512;
const CUSTOM_EMOJI_MAX_ENTRIES = 500;
const CUSTOM_EMOJI_INDEX_KEY = 'custom_emoji.registry.v1';
const CUSTOM_EMOJI_RECORD_PREFIX = 'custom_emoji.item.';
const CUSTOM_EMOJI_NAME_PREFIX = 'custom_emoji.name.';
const CUSTOM_EMOJI_IMAGE_TYPES = [
    'image/png' => ['png', IMAGETYPE_PNG],
    'image/gif' => ['gif', IMAGETYPE_GIF],
    'image/webp' => ['webp', IMAGETYPE_WEBP],
    'image/jpeg' => ['jpg', IMAGETYPE_JPEG],
];

final class CustomEmojiException extends RuntimeException {
    public function __construct(string $message, public readonly int $httpStatus = 400) {
        parent::__construct($message);
    }
}

function custom_emoji_can_manage(PDO $pdo, array $user): bool {
    return ($user['role'] ?? '') === 'admin'
        || moderation_identity_is_owner($pdo, (int)($user['id'] ?? 0));
}

function custom_emoji_valid_id(mixed $id): bool {
    return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/', $id) === 1;
}

function custom_emoji_index(PDO $pdo, bool $locked = false): array {
    $statement = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=? LIMIT 1'
        . ($locked && db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
    $statement->execute([CUSTOM_EMOJI_INDEX_KEY]);
    $raw = $statement->fetchColumn();
    if ($raw === false) return [];
    if (strlen((string)$raw) > 40000) throw new CustomEmojiException('The custom emoji catalog is unavailable.', 500);
    try { $ids = json_decode((string)$raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new CustomEmojiException('The custom emoji catalog is unavailable.', 500); }
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > CUSTOM_EMOJI_MAX_ENTRIES) {
        throw new CustomEmojiException('The custom emoji catalog is unavailable.', 500);
    }
    $seen = [];
    foreach ($ids as $id) {
        if (!custom_emoji_valid_id($id) || isset($seen[$id])) throw new CustomEmojiException('The custom emoji catalog is unavailable.', 500);
        $seen[$id] = true;
    }
    return $ids;
}

function custom_emoji_decode_record(string $raw, string $id): array {
    if (strlen($raw) > 4096) throw new CustomEmojiException('The custom emoji record is unavailable.', 500);
    try { $record = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new CustomEmojiException('The custom emoji record is unavailable.', 500); }
    if (!is_array($record) || ($record['id'] ?? null) !== $id
        || !is_string($record['name'] ?? null) || preg_match('/\A[a-z0-9_-]{1,32}\z/', $record['name']) !== 1
        || !is_string($record['file'] ?? null) || preg_match('/\A[a-f0-9]{32}\.(png|gif|webp|jpg)\z/', $record['file']) !== 1
        || !is_string($record['mime'] ?? null) || !isset(CUSTOM_EMOJI_IMAGE_TYPES[$record['mime']])
        || pathinfo($record['file'], PATHINFO_EXTENSION) !== CUSTOM_EMOJI_IMAGE_TYPES[$record['mime']][0]
        || !is_int($record['width'] ?? null) || $record['width'] < 1 || $record['width'] > CUSTOM_EMOJI_MAX_WIDTH
        || !is_int($record['height'] ?? null) || $record['height'] < 1 || $record['height'] > CUSTOM_EMOJI_MAX_HEIGHT
        || !is_int($record['bytes'] ?? null) || $record['bytes'] < 1 || $record['bytes'] > CUSTOM_EMOJI_MAX_BYTES
        || !is_string($record['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $record['sha256']) !== 1) {
        throw new CustomEmojiException('The custom emoji record is unavailable.', 500);
    }
    return $record;
}

function custom_emoji_record(PDO $pdo, string $id): ?array {
    if (!custom_emoji_valid_id($id)) return null;
    $statement = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=? LIMIT 1');
    $statement->execute([CUSTOM_EMOJI_RECORD_PREFIX . $id]);
    $raw = $statement->fetchColumn();
    return $raw === false ? null : custom_emoji_decode_record((string)$raw, $id);
}

function custom_emoji_public(array $record): array {
    return [
        'id' => $record['id'], 'name' => $record['name'],
        'url' => app_url('/api/custom_emojis.php?action=image&id=' . $record['id']),
        'width' => $record['width'], 'height' => $record['height'],
    ];
}

function custom_emoji_snapshot(PDO $pdo, array $user): array {
    $ids = custom_emoji_index($pdo);
    $emojis = [];
    if ($ids) {
        $keys = array_map(static fn(string $id): string => CUSTOM_EMOJI_RECORD_PREFIX . $id, $ids);
        $statement = $pdo->prepare('SELECT setting_key,value FROM app_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
        $statement->execute($keys);
        $records = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($ids as $id) {
            $key = CUSTOM_EMOJI_RECORD_PREFIX . $id;
            if (!isset($records[$key])) throw new CustomEmojiException('The custom emoji catalog is unavailable.', 500);
            $emojis[] = custom_emoji_public(custom_emoji_decode_record((string)$records[$key], $id));
        }
        usort($emojis, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));
    }
    return [
        'emojis' => $emojis, 'canManage' => custom_emoji_can_manage($pdo, $user),
        'maxBytes' => CUSTOM_EMOJI_MAX_BYTES, 'maxWidth' => CUSTOM_EMOJI_MAX_WIDTH,
        'maxHeight' => CUSTOM_EMOJI_MAX_HEIGHT, 'maxEntries' => CUSTOM_EMOJI_MAX_ENTRIES,
    ];
}

function custom_emoji_storage_directory(bool $create = false): ?string {
    $uploads = realpath(dirname(__DIR__) . '/assets/uploads');
    if (!$uploads) {
        if ($create) throw new CustomEmojiException('Custom emoji storage is unavailable.', 500);
        return null;
    }
    $path = $uploads . DIRECTORY_SEPARATOR . 'emojis';
    if ($create && !is_dir($path) && !@mkdir($path, 0755) && !is_dir($path)) {
        throw new CustomEmojiException('Custom emoji storage is unavailable.', 500);
    }
    $directory = realpath($path);
    if (!$directory || $directory !== $path) {
        if ($create) throw new CustomEmojiException('Custom emoji storage is unavailable.', 500);
        return null;
    }
    return $directory;
}

function custom_emoji_upload(PDO $pdo, int $userId, mixed $nameValue, mixed $file): array {
    $name = is_string($nameValue) ? trim($nameValue) : '';
    if (preg_match('/\A[a-z0-9_-]{1,32}\z/', $name) !== 1) {
        throw new CustomEmojiException('Use a unique name of 1-32 lowercase letters, digits, underscores or hyphens.');
    }
    if (!is_array($file) || !is_int($file['error'] ?? null)) throw new CustomEmojiException('Choose a PNG, GIF, WebP or JPEG image.');
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new CustomEmojiException('The image must be no larger than 5 MiB.', 413);
    if (in_array($file['error'], [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) throw new CustomEmojiException('The image upload could not be received. Try again.', 500);
    $temporary = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
    if ($file['error'] !== UPLOAD_ERR_OK || $temporary === '' || !is_uploaded_file($temporary)) throw new CustomEmojiException('Choose a complete PNG, GIF, WebP or JPEG image.');
    $bytes = filesize($temporary);
    if (!is_int($bytes) || $bytes < 1) throw new CustomEmojiException('The image is empty or unavailable.');
    if ($bytes > CUSTOM_EMOJI_MAX_BYTES) throw new CustomEmojiException('The image must be no larger than 5 MiB.', 413);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: '';
    $dimensions = @getimagesize($temporary);
    if (!isset(CUSTOM_EMOJI_IMAGE_TYPES[$mime]) || !$dimensions || (int)$dimensions[2] !== CUSTOM_EMOJI_IMAGE_TYPES[$mime][1]) {
        throw new CustomEmojiException('Use a valid raster PNG, GIF, WebP or JPEG image. SVG is not supported.');
    }
    if ((int)$dimensions[0] < 1 || (int)$dimensions[1] < 1 || (int)$dimensions[0] > CUSTOM_EMOJI_MAX_WIDTH || (int)$dimensions[1] > CUSTOM_EMOJI_MAX_HEIGHT) {
        throw new CustomEmojiException('Image width and height must each be between 1 and 512 pixels.');
    }
    $hash = hash_file('sha256', $temporary);
    if (!is_string($hash)) throw new CustomEmojiException('The image could not be read.', 500);
    $transaction = database_transaction_begin($pdo, true);
    if (empty($transaction['owned'])) throw new LogicException('Custom emoji upload must own its transaction.');
    $createdPath = null;
    try {
        $initialize = $pdo->prepare(db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO app_settings (setting_key,value) VALUES (?,?)'
            : 'INSERT OR IGNORE INTO app_settings (setting_key,value) VALUES (?,?)');
        $initialize->execute([CUSTOM_EMOJI_INDEX_KEY, '[]']);
        $ids = custom_emoji_index($pdo, true);
        if (count($ids) >= CUSTOM_EMOJI_MAX_ENTRIES) throw new CustomEmojiException('The custom emoji library already contains 500 entries.', 409);
        if (app_setting($pdo, CUSTOM_EMOJI_NAME_PREFIX . $name, '') !== '') throw new CustomEmojiException('That custom emoji name is already in use.', 409);
        $id = '';
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $candidate = bin2hex(random_bytes(16));
            if (app_setting($pdo, CUSTOM_EMOJI_RECORD_PREFIX . $candidate, '') === '') { $id = $candidate; break; }
        }
        if ($id === '') throw new CustomEmojiException('A custom emoji identifier could not be allocated.', 500);
        // The public token ID does not reveal the independently random storage filename.
        $filename = bin2hex(random_bytes(16)) . '.' . CUSTOM_EMOJI_IMAGE_TYPES[$mime][0];
        $publicPath = '/assets/uploads/emojis/' . $filename;
        security_assert_storage_destination('custom_emoji_upload', $publicPath);
        $directory = custom_emoji_storage_directory(true);
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        $output = @fopen($destination, 'xb');
        if ($output === false) throw new CustomEmojiException('The image could not be stored.', 500);
        $createdPath = $destination;
        $input = @fopen($temporary, 'rb');
        try {
            if ($input === false || stream_copy_to_stream($input, $output) !== $bytes) throw new CustomEmojiException('The image could not be stored completely.', 500);
        } finally {
            if (is_resource($input)) fclose($input);
            fclose($output);
        }
        $storedHash = hash_file('sha256', $destination);
        if (!is_string($storedHash) || !hash_equals($hash, $storedHash)) throw new CustomEmojiException('The image could not be stored completely.', 500);
        $record = [
            'id' => $id, 'name' => $name, 'file' => $filename, 'mime' => $mime,
            'width' => (int)$dimensions[0], 'height' => (int)$dimensions[1],
            'bytes' => $bytes, 'sha256' => $hash, 'createdBy' => $userId, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $insert = $pdo->prepare('INSERT INTO app_settings (setting_key,value) VALUES (?,?)');
        $insert->execute([CUSTOM_EMOJI_RECORD_PREFIX . $id, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $insert->execute([CUSTOM_EMOJI_NAME_PREFIX . $name, $id]);
        $ids[] = $id;
        set_app_setting($pdo, CUSTOM_EMOJI_INDEX_KEY, json_encode($ids, JSON_THROW_ON_ERROR));
        database_transaction_commit($pdo, $transaction);
        return custom_emoji_public($record);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        if ($createdPath !== null) @unlink($createdPath);
        throw $error;
    }
}

/** Remove picker membership; immutable history remains resolvable. */
function custom_emoji_delete(PDO $pdo, mixed $id): void {
    if (!custom_emoji_valid_id($id)) throw new CustomEmojiException('Custom emoji unavailable.', 404);
    $transaction = database_transaction_begin($pdo, true);
    if (empty($transaction['owned'])) throw new LogicException('Custom emoji deletion must own its transaction.');
    try {
        $ids = custom_emoji_index($pdo, true);
        $record = custom_emoji_record($pdo, $id);
        if (!$record) throw new CustomEmojiException('Custom emoji unavailable.', 404);
        if (in_array($id, $ids, true)) {
            set_app_setting($pdo, CUSTOM_EMOJI_INDEX_KEY, json_encode(array_values(array_diff($ids, [$id])), JSON_THROW_ON_ERROR));
            // Do not release a replacement's reservation on a repeated old deletion.
            $pdo->prepare('DELETE FROM app_settings WHERE setting_key = ? AND value = ?')
                ->execute([CUSTOM_EMOJI_NAME_PREFIX . $record['name'], $id]);
        }
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function custom_emoji_serve_image(PDO $pdo, mixed $id): never {
    if (!custom_emoji_valid_id($id)) json_out(['error' => 'Custom emoji unavailable.'], 404);
    // Historical resolution deliberately does not depend on membership in the picker index.
    // A future soft deletion must retain this immutable record and its original bytes.
    $record = custom_emoji_record($pdo, $id);
    $directory = custom_emoji_storage_directory();
    $path = $record && $directory ? realpath($directory . DIRECTORY_SEPARATOR . $record['file']) : false;
    if (!$record || !$directory || !$path || dirname($path) !== $directory || !is_file($path)) json_out(['error' => 'Custom emoji unavailable.'], 404);
    $stream = @fopen($path, 'rb');
    if ($stream === false) json_out(['error' => 'Custom emoji unavailable.'], 404);
    $stat = fstat($stream);
    if (!$stat || (int)$stat['size'] !== $record['bytes']) {
        fclose($stream);
        json_out(['error' => 'Custom emoji unavailable.'], 404);
    }
    $etag = '"' . $record['sha256'] . '"';
    header('Content-Type: ' . $record['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Vary: Cookie');
    header('ETag: ' . $etag);
    header_remove('Pragma');
    header('Content-Disposition: inline; filename="' . $id . '.' . CUSTOM_EMOJI_IMAGE_TYPES[$record['mime']][0] . '"');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        fclose($stream);
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . $record['bytes']);
    fpassthru($stream);
    fclose($stream);
    exit;
}
