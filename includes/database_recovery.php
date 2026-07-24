<?php
declare(strict_types=1);

/**
 * Build 000048 Part 2 authoritative recovery owner.
 *
 * Recovery state and paired manifests live in protected private storage so a
 * database restore cannot erase the very state needed to finish or diagnose
 * recovery. Browser adapters receive bounded summaries, never storage paths.
 */

const CORE_RECOVERY_STATE_SCHEMA = 'corechat-recovery-state-v1';
const CORE_RECOVERY_SET_SCHEMA = 'corechat-paired-recovery-set-v1';
const CORE_RELEASE_MANIFEST_SCHEMA = 'corechat-deployed-release-v1';
const CORE_RECOVERY_MAX_JSON_BYTES = 4194304;
const CORE_RECOVERY_COPY_CHUNK_BYTES = 1048576;

function database_recovery_public_root(): string
{
    if (PHP_SAPI === 'cli'
        && defined('CHATSPACE_RECOVERY_TEST_MODE')
        && CHATSPACE_RECOVERY_TEST_MODE === true
        && defined('CHATSPACE_RECOVERY_APPLICATION_ROOT')) {
        $configured = realpath((string)CHATSPACE_RECOVERY_APPLICATION_ROOT);
        if (!is_string($configured) || !is_dir($configured)) {
            throw new CoreMigrationException(
                'Recovery test application root is invalid.',
                'RECOVERY_TEST_ROOT_INVALID',
                500
            );
        }
        return $configured;
    }
    return dirname(__DIR__);
}

function database_recovery_root(): string
{
    return security_private_storage_directory('database-recovery');
}

function database_recovery_state_path(): string
{
    return database_recovery_root() . DIRECTORY_SEPARATOR . 'recovery-state.json';
}

function database_recovery_canonical_json(array $value): string
{
    return database_migrations_canonical_json($value) . "\n";
}

function database_recovery_atomic_write(string $path, string $content): void
{
    $length = strlen($content);
    if ($length < 2 || $length > CORE_RECOVERY_MAX_JSON_BYTES) {
        throw new CoreMigrationException(
            'Recovery metadata violates the bounded size ceiling.',
            'RECOVERY_METADATA_SIZE_INVALID',
            500
        );
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new CoreMigrationException('Private recovery storage could not be created.', 'RECOVERY_STORAGE_CREATE_FAILED', 500);
    }
    $temporary = $path . '.partial-' . bin2hex(random_bytes(8));
    $handle = fopen($temporary, 'xb');
    if (!is_resource($handle)) {
        throw new CoreMigrationException('Recovery metadata could not be staged.', 'RECOVERY_METADATA_WRITE_FAILED', 500);
    }
    try {
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new CoreMigrationException('Recovery metadata write failed.', 'RECOVERY_METADATA_WRITE_FAILED', 500);
            }
            $offset += $written;
        }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new CoreMigrationException('Recovery metadata could not be synchronized.', 'RECOVERY_METADATA_FLUSH_FAILED', 500);
        }
    } finally {
        fclose($handle);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new CoreMigrationException('Recovery metadata could not be finalized.', 'RECOVERY_METADATA_FINALIZE_FAILED', 500);
    }
    @chmod($path, 0600);
}

function database_recovery_read_json(string $path, string $errorCode): array
{
    clearstatcache(true, $path);
    $size = is_file($path) ? filesize($path) : false;
    if ($size === false || $size < 2 || $size > CORE_RECOVERY_MAX_JSON_BYTES) {
        throw new CoreMigrationException('Recovery metadata is missing or outside its size boundary.', $errorCode, 409);
    }
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new CoreMigrationException('Recovery metadata is not readable.', $errorCode, 409);
    }
    try {
        $json = stream_get_contents($handle, CORE_RECOVERY_MAX_JSON_BYTES + 1);
    } finally {
        fclose($handle);
    }
    if (!is_string($json) || strlen($json) !== (int)$size) {
        throw new CoreMigrationException('Recovery metadata could not be read completely.', $errorCode, 409);
    }
    try {
        $value = json_decode($json, true, 256, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new CoreMigrationException('Recovery metadata is malformed.', $errorCode, 409, $error);
    }
    if (!is_array($value)) {
        throw new CoreMigrationException('Recovery metadata is not an object.', $errorCode, 409);
    }
    return $value;
}

function database_recovery_default_state(): array
{
    return [
        'schema' => CORE_RECOVERY_STATE_SCHEMA,
        'revision' => 0,
        'maintenance' => false,
        'phase' => 'idle',
        'active_recovery_set_id' => null,
        'request_public_id' => null,
        'updated_at' => gmdate('c'),
        'last_error_code' => null,
        'last_error_message' => null,
    ];
}

function database_recovery_state(): array
{
    $path = database_recovery_state_path();
    if (!is_file($path)) return database_recovery_default_state();
    $state = database_recovery_read_json($path, 'RECOVERY_STATE_INVALID');
    if (($state['schema'] ?? '') !== CORE_RECOVERY_STATE_SCHEMA
        || !is_int($state['revision'] ?? null)
        || !is_bool($state['maintenance'] ?? null)
        || !is_string($state['phase'] ?? null)) {
        throw new CoreMigrationException('Private recovery state is incompatible.', 'RECOVERY_STATE_INVALID', 409);
    }
    return $state;
}

function database_recovery_write_state(array $changes): array
{
    $state = database_recovery_state();
    foreach ($changes as $key => $value) $state[$key] = $value;
    $state['schema'] = CORE_RECOVERY_STATE_SCHEMA;
    $state['revision'] = (int)($state['revision'] ?? 0) + 1;
    $state['updated_at'] = gmdate('c');
    database_recovery_atomic_write(database_recovery_state_path(), database_recovery_canonical_json($state));
    return $state;
}

function database_recovery_installation_identity(): string
{
    $path = database_recovery_root() . DIRECTORY_SEPARATOR . 'installation.json';
    if (!is_file($path)) {
        $record = [
            'schema' => 'corechat-installation-identity-v1',
            'installation_id' => uuid_v4(),
            'created_at' => gmdate('c'),
        ];
        $content = database_recovery_canonical_json($record);
        $handle = @fopen($path, 'xb');
        if (is_resource($handle)) {
            try {
                if (fwrite($handle, $content) !== strlen($content)
                    || !fflush($handle)
                    || (function_exists('fsync') && !fsync($handle))) {
                    throw new CoreMigrationException(
                        'Installation identity could not be synchronized.',
                        'RECOVERY_INSTALLATION_IDENTITY_FAILED',
                        500
                    );
                }
            } finally {
                fclose($handle);
            }
            @chmod($path, 0600);
        }
    }
    $record = database_recovery_read_json($path, 'RECOVERY_INSTALLATION_IDENTITY_FAILED');
    $identity = (string)($record['installation_id'] ?? '');
    if (($record['schema'] ?? '') !== 'corechat-installation-identity-v1'
        || !preg_match('/^[a-f0-9-]{36}$/i', $identity)) {
        throw new CoreMigrationException(
            'Installation identity is invalid.',
            'RECOVERY_INSTALLATION_IDENTITY_FAILED',
            500
        );
    }
    return strtolower($identity);
}

function database_recovery_normalize_relative_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === ''
        || strlen($path) > 512
        || str_starts_with($path, '/')
        || preg_match('/^[A-Za-z]:/', $path)
        || str_contains($path, "\0")) {
        throw new CoreMigrationException('Release inventory contains an unsafe path.', 'RELEASE_MANIFEST_PATH_INVALID', 409);
    }
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new CoreMigrationException('Release inventory contains path traversal.', 'RELEASE_MANIFEST_PATH_INVALID', 409);
        }
    }
    $lower = strtolower($path);
    if ($lower === 'includes/config.php'
        || str_starts_with($lower, 'framework/')
        || str_starts_with($lower, 'deployment/')
        || str_starts_with($lower, '.git/')
        || str_starts_with($lower, 'db/') && $lower !== 'db/.htaccess'
        || str_starts_with($lower, 'assets/uploads/')
            && $lower !== 'assets/uploads/.htaccess'
            && !str_ends_with($lower, '/.gitkeep')) {
        throw new CoreMigrationException(
            'Release inventory includes preserve-on-host or private content.',
            'RELEASE_MANIFEST_PRESERVE_PATH',
            409
        );
    }
    return implode('/', $segments);
}

function database_recovery_release_manifest(
    bool $verifyFiles = true,
    bool $requireLoadedSchemaMatch = true
): array
{
    $root = database_recovery_public_root();
    $path = $root . DIRECTORY_SEPARATOR . 'release-manifest.json';
    $manifest = database_recovery_read_json($path, 'RELEASE_MANIFEST_INVALID');
    $files = $manifest['files'] ?? null;
    if (($manifest['schema'] ?? '') !== CORE_RELEASE_MANIFEST_SCHEMA
        || !is_array($files)
        || $files === []
        || !is_string($manifest['release_id'] ?? null)
        || !is_string($manifest['required_schema_version'] ?? null)) {
        throw new CoreMigrationException('The deployed release manifest is incomplete.', 'RELEASE_MANIFEST_INVALID', 409);
    }
    $normalized = [];
    $caseOwners = [];
    $totalBytes = 0;
    foreach ($files as $relative => $metadata) {
        if (!is_string($relative) || !is_array($metadata)) {
            throw new CoreMigrationException('The deployed release inventory is malformed.', 'RELEASE_MANIFEST_INVALID', 409);
        }
        $relative = database_recovery_normalize_relative_path($relative);
        $caseKey = strtolower($relative);
        if (isset($caseOwners[$caseKey])) {
            throw new CoreMigrationException('The deployed release inventory has a case collision.', 'RELEASE_MANIFEST_CASE_COLLISION', 409);
        }
        $caseOwners[$caseKey] = true;
        $bytes = $metadata['bytes'] ?? null;
        $sha = strtoupper((string)($metadata['sha256'] ?? ''));
        if (!is_int($bytes) || $bytes < 0 || !preg_match('/^[A-F0-9]{64}$/', $sha)) {
            throw new CoreMigrationException('The deployed release inventory has invalid file metadata.', 'RELEASE_MANIFEST_INVALID', 409);
        }
        $normalized[$relative] = ['bytes' => $bytes, 'sha256' => $sha];
        $totalBytes += $bytes;
    }
    ksort($normalized, SORT_STRING);
    $identity = [
        'schema' => CORE_RELEASE_MANIFEST_SCHEMA,
        'application_version' => (string)($manifest['application_version'] ?? ''),
        'required_schema_version' => (string)$manifest['required_schema_version'],
        'files' => $normalized,
    ];
    $expectedId = strtoupper(hash('sha256', database_recovery_canonical_json($identity)));
    if (!hash_equals($expectedId, strtoupper((string)$manifest['release_id']))
        || (int)($manifest['total_files'] ?? -1) !== count($normalized)
        || (int)($manifest['total_bytes'] ?? -1) !== $totalBytes) {
        throw new CoreMigrationException('The deployed release identity does not match its inventory.', 'RELEASE_MANIFEST_IDENTITY_MISMATCH', 409);
    }
    if ($requireLoadedSchemaMatch
        && (string)$manifest['required_schema_version'] !== CHATSPACE_SCHEMA_VERSION) {
        throw new CoreMigrationException(
            'The deployed release manifest and runtime schema authority differ.',
            'RELEASE_MANIFEST_SCHEMA_MISMATCH',
            409
        );
    }
    if ($verifyFiles) {
        foreach ($normalized as $relative => $metadata) {
            $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            clearstatcache(true, $candidate);
            $size = is_file($candidate) && !is_link($candidate) ? filesize($candidate) : false;
            $sha = $size === false ? false : hash_file('sha256', $candidate);
            if ($size === false
                || (int)$size !== $metadata['bytes']
                || !is_string($sha)
                || !hash_equals($metadata['sha256'], strtoupper($sha))) {
                throw new CoreMigrationException(
                    'The deployed application release is incomplete or mixed at: ' . $relative . '.',
                    'RELEASE_FILE_MISMATCH',
                    409
                );
            }
        }
    }
    $manifestSize = filesize($path);
    $manifestSha = hash_file('sha256', $path);
    if ($manifestSize === false || !is_string($manifestSha)) {
        throw new CoreMigrationException('The release manifest identity could not be read.', 'RELEASE_MANIFEST_INVALID', 409);
    }
    $normalized['release-manifest.json'] = [
        'bytes' => (int)$manifestSize,
        'sha256' => strtoupper($manifestSha),
    ];
    ksort($normalized, SORT_STRING);
    return [
        'release_id' => strtoupper((string)$manifest['release_id']),
        'application_version' => (string)($manifest['application_version'] ?? ''),
        'required_schema_version' => (string)$manifest['required_schema_version'],
        'inventory_schema' => CORE_RELEASE_MANIFEST_SCHEMA,
        'inventory' => $normalized,
        'inventory_sha256' => strtoupper((string)($manifest['inventory_sha256'] ?? $manifest['release_id'])),
        'manifest_sha256' => strtoupper($manifestSha),
        'file_count' => count($normalized),
        'byte_size' => array_sum(array_column($normalized, 'bytes')),
        'verified' => $verifyFiles,
    ];
}

function database_recovery_stream_copy(
    string $source,
    string $destination,
    ?int $expectedBytes = null,
    ?string $expectedSha256 = null
): array {
    if (!is_file($source) || is_link($source)) {
        throw new CoreMigrationException('Recovery source file is unavailable.', 'RECOVERY_COPY_SOURCE_INVALID', 409);
    }
    $directory = dirname($destination);
    if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new CoreMigrationException('Recovery destination could not be created.', 'RECOVERY_COPY_DESTINATION_FAILED', 500);
    }
    $input = fopen($source, 'rb');
    $output = fopen($destination, 'xb');
    if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        throw new CoreMigrationException('Recovery file stream could not be opened.', 'RECOVERY_COPY_OPEN_FAILED', 500);
    }
    $hash = hash_init('sha256');
    $bytes = 0;
    try {
        while (!feof($input)) {
            $chunk = fread($input, CORE_RECOVERY_COPY_CHUNK_BYTES);
            if ($chunk === false) {
                throw new CoreMigrationException('Recovery source stream failed.', 'RECOVERY_COPY_READ_FAILED', 500);
            }
            if ($chunk === '') continue;
            hash_update($hash, $chunk);
            $length = strlen($chunk);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($output, substr($chunk, $offset));
                if ($written === false || $written === 0) {
                    throw new CoreMigrationException('Recovery destination stream failed.', 'RECOVERY_COPY_WRITE_FAILED', 500);
                }
                $offset += $written;
                $bytes += $written;
            }
        }
        if (!fflush($output) || (function_exists('fsync') && !fsync($output))) {
            throw new CoreMigrationException('Recovery copy could not be synchronized.', 'RECOVERY_COPY_FLUSH_FAILED', 500);
        }
    } catch (Throwable $error) {
        fclose($input);
        fclose($output);
        @unlink($destination);
        throw $error;
    }
    fclose($input);
    fclose($output);
    $sha = strtoupper(hash_final($hash));
    if (($expectedBytes !== null && $bytes !== $expectedBytes)
        || ($expectedSha256 !== null && !hash_equals(strtoupper($expectedSha256), $sha))) {
        @unlink($destination);
        throw new CoreMigrationException('Recovery copy identity does not match.', 'RECOVERY_COPY_MISMATCH', 409);
    }
    @chmod($destination, 0600);
    return ['bytes' => $bytes, 'sha256' => $sha];
}

function database_recovery_snapshot_application(array $release, string $destination): array
{
    $root = database_recovery_public_root();
    $filesRoot = $destination . DIRECTORY_SEPARATOR . 'files';
    $copied = [];
    foreach ($release['inventory'] as $relative => $metadata) {
        $source = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $target = $filesRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $copy = database_recovery_stream_copy(
            $source,
            $target,
            (int)$metadata['bytes'],
            (string)$metadata['sha256']
        );
        $copied[$relative] = $copy;
    }
    if ($copied != $release['inventory']) {
        throw new CoreMigrationException('Application snapshot inventory changed while copying.', 'APPLICATION_SNAPSHOT_MISMATCH', 409);
    }
    $snapshot = [
        'schema' => 'corechat-application-snapshot-v1',
        'release_id' => $release['release_id'],
        'application_version' => $release['application_version'],
        'required_schema_version' => $release['required_schema_version'],
        'deployment_inventory_schema' => $release['inventory_schema'],
        'deployment_inventory_sha256' => $release['inventory_sha256'],
        'release_manifest_sha256' => $release['manifest_sha256'],
        'file_count' => $release['file_count'],
        'byte_size' => $release['byte_size'],
        'files' => $copied,
        'preserve_on_host_excluded' => true,
        'verified_at' => gmdate('c'),
        'availability' => 'available',
    ];
    $manifestPath = $destination . DIRECTORY_SEPARATOR . 'snapshot-manifest.json';
    database_recovery_atomic_write($manifestPath, database_recovery_canonical_json($snapshot));
    $sha = hash_file('sha256', $manifestPath);
    if (!is_string($sha)) {
        throw new CoreMigrationException('Application snapshot manifest could not be verified.', 'APPLICATION_SNAPSHOT_MANIFEST_FAILED', 500);
    }
    $snapshot['snapshot_manifest_sha256'] = strtoupper($sha);
    return $snapshot;
}

function database_recovery_recovery_set_path(string $recoverySetId): string
{
    if (!preg_match('/^[a-f0-9-]{36}$/i', $recoverySetId)) {
        throw new CoreMigrationException('Recovery-set identity is invalid.', 'RECOVERY_SET_ID_INVALID', 400);
    }
    return database_recovery_root() . DIRECTORY_SEPARATOR . 'sets' . DIRECTORY_SEPARATOR . strtolower($recoverySetId);
}

function database_recovery_manifest_path(string $recoverySetId): string
{
    return database_recovery_recovery_set_path($recoverySetId) . DIRECTORY_SEPARATOR . 'manifest.json';
}

function database_recovery_catalog_record(array $manifest): void
{
    $path = database_recovery_root() . DIRECTORY_SEPARATOR . 'catalog.json';
    $catalog = is_file($path)
        ? database_recovery_read_json($path, 'RECOVERY_CATALOG_INVALID')
        : ['schema' => 'corechat-recovery-catalog-v1', 'sets' => []];
    if (($catalog['schema'] ?? '') !== 'corechat-recovery-catalog-v1' || !is_array($catalog['sets'] ?? null)) {
        throw new CoreMigrationException('Recovery catalog is invalid.', 'RECOVERY_CATALOG_INVALID', 409);
    }
    $id = (string)$manifest['recovery_set_id'];
    $entry = [
        'recovery_set_id' => $id,
        'created_at' => $manifest['created_at'],
        'source_release_id' => $manifest['source_application']['release_id'],
        'source_schema_version' => $manifest['source_database']['schema_version'],
        'engine' => $manifest['source_database']['engine'],
        'status' => $manifest['status'],
        'manifest_sha256' => strtoupper((string)hash_file('sha256', database_recovery_manifest_path($id))),
    ];
    if (isset($catalog['sets'][$id]) && $catalog['sets'][$id] != $entry) {
        throw new CoreMigrationException('Recovery catalog identity changed.', 'RECOVERY_CATALOG_MISMATCH', 409);
    }
    $catalog['sets'][$id] = $entry;
    ksort($catalog['sets'], SORT_STRING);
    $catalog['updated_at'] = gmdate('c');
    database_recovery_atomic_write($path, database_recovery_canonical_json($catalog));
}

function database_recovery_claim(PDO $pdo): array
{
    $path = database_recovery_root() . DIRECTORY_SEPARATOR . 'recovery.lock';
    $handle = fopen($path, 'c+b');
    if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) fclose($handle);
        throw new CoreMigrationException('Another recovery owner is active.', 'RECOVERY_ALREADY_ACTIVE', 409);
    }
    $databaseLock = null;
    try {
        if (db_driver($pdo) === 'mysql') {
            $databaseLock = 'corechat_recovery_' . substr(
                hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()),
                0,
                32
            );
            $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $statement->execute([$databaseLock]);
            $acquired = (int)$statement->fetchColumn() === 1;
            $statement->closeCursor();
            if (!$acquired) {
                throw new CoreMigrationException('Another MariaDB recovery owner is active.', 'RECOVERY_ALREADY_ACTIVE', 409);
            }
        }
        return [
            'handle' => $handle,
            'database_lock' => $databaseLock,
            'pdo' => $databaseLock === null ? null : $pdo,
        ];
    } catch (Throwable $error) {
        flock($handle, LOCK_UN);
        fclose($handle);
        throw $error;
    }
}

function database_recovery_release_claim(array $claim): void
{
    $pdo = $claim['pdo'] ?? null;
    $databaseLock = $claim['database_lock'] ?? null;
    if ($pdo instanceof PDO && is_string($databaseLock) && $databaseLock !== '') {
        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$databaseLock]);
            $statement->closeCursor();
        } catch (Throwable) {
        }
    }
    $handle = $claim['handle'] ?? null;
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function database_recovery_safe_log(PDO $pdo, int $actorUserId, string $action, array $detail): void
{
    $event = [
        'schema' => 'corechat-recovery-event-v1',
        'event_id' => uuid_v4(),
        'created_at' => gmdate('c'),
        'actor_user_id' => $actorUserId,
        'action' => $action,
        'detail' => $detail,
    ];
    $line = database_recovery_canonical_json($event);
    if (strlen($line) > 65536) {
        throw new CoreMigrationException('Recovery event exceeds its bounded record size.', 'RECOVERY_EVENT_SIZE_INVALID', 500);
    }
    $path = database_recovery_root() . DIRECTORY_SEPARATOR . 'events.jsonl';
    $handle = fopen($path, 'ab');
    if (!is_resource($handle)) {
        throw new CoreMigrationException('Private recovery event journal is unavailable.', 'RECOVERY_EVENT_JOURNAL_FAILED', 500);
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new CoreMigrationException('Private recovery event lock failed.', 'RECOVERY_EVENT_JOURNAL_FAILED', 500);
        }
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($handle, substr($line, $offset));
            if ($written === false || $written === 0) {
                throw new CoreMigrationException('Private recovery event write failed.', 'RECOVERY_EVENT_JOURNAL_FAILED', 500);
            }
            $offset += $written;
        }
        if (!fflush($handle)
            || (function_exists('fsync') && !fsync($handle))) {
            throw new CoreMigrationException('Private recovery event could not be recorded.', 'RECOVERY_EVENT_JOURNAL_FAILED', 500);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    @chmod($path, 0600);
    database_recovery_insert_event_log($pdo, $event);
}

function database_recovery_insert_event_log(PDO $pdo, array $event): void
{
    if (!database_migration_table_exists($pdo, 'tool_logs')) return;
    $detail = (array)($event['detail'] ?? []);
    $detail['recovery_event_id'] = (string)$event['event_id'];
    $encoded = database_recovery_canonical_json($detail);
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM tool_logs WHERE action = ? AND detail = ?'
    );
    $statement->execute([(string)$event['action'], $encoded]);
    $exists = (int)$statement->fetchColumn() !== 0;
    $statement->closeCursor();
    if ($exists) return;
    log_tool(
        $pdo,
        (int)$event['actor_user_id'],
        (string)$event['action'],
        null,
        null,
        $encoded
    );
}

function database_recovery_replay_event_logs(PDO $pdo): int
{
    $path = database_recovery_root() . DIRECTORY_SEPARATOR . 'events.jsonl';
    if (!is_file($path)) return 0;
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new CoreMigrationException('Private recovery event journal is unreadable.', 'RECOVERY_EVENT_JOURNAL_FAILED', 500);
    }
    $replayed = 0;
    try {
        while (($line = fgets($handle, 65537)) !== false) {
            if ($line === '' || !str_ends_with($line, "\n") || strlen($line) > 65536) {
                throw new CoreMigrationException('Private recovery event record is invalid.', 'RECOVERY_EVENT_JOURNAL_INVALID', 500);
            }
            $event = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($event)
                || ($event['schema'] ?? '') !== 'corechat-recovery-event-v1'
                || !preg_match('/^[a-f0-9-]{36}$/i', (string)($event['event_id'] ?? ''))
                || !preg_match('/^database_recovery_[a-z0-9_]+$/', (string)($event['action'] ?? ''))
                || !is_int($event['actor_user_id'] ?? null)
                || !is_array($event['detail'] ?? null)) {
                throw new CoreMigrationException('Private recovery event record is incompatible.', 'RECOVERY_EVENT_JOURNAL_INVALID', 500);
            }
            database_recovery_insert_event_log($pdo, $event);
            $replayed++;
        }
    } catch (JsonException $error) {
        throw new CoreMigrationException('Private recovery event JSON is invalid.', 'RECOVERY_EVENT_JOURNAL_INVALID', 500, $error);
    } finally {
        fclose($handle);
    }
    return $replayed;
}

function database_recovery_verify_set(PDO $pdo, string $recoverySetId, bool $verifyApplication = true): array
{
    $manifestPath = database_recovery_manifest_path($recoverySetId);
    $manifest = database_recovery_read_json($manifestPath, 'RECOVERY_SET_MANIFEST_INVALID');
    if (($manifest['schema'] ?? '') !== CORE_RECOVERY_SET_SCHEMA
        || !hash_equals(strtolower($recoverySetId), strtolower((string)($manifest['recovery_set_id'] ?? '')))
        || !hash_equals(database_recovery_installation_identity(), strtolower((string)($manifest['installation_id'] ?? '')))
        || ($manifest['status'] ?? '') !== 'verified') {
        throw new CoreMigrationException('The paired recovery set is invalid or belongs to another installation.', 'RECOVERY_SET_MISMATCH', 409);
    }
    $application = (array)($manifest['application_snapshot'] ?? []);
    $files = (array)($application['files'] ?? []);
    if ($files === []
        || (int)($application['file_count'] ?? -1) !== count($files)
        || !hash_equals(
            strtoupper((string)($manifest['source_application']['release_id'] ?? '')),
            strtoupper((string)($application['release_id'] ?? ''))
        )) {
        throw new CoreMigrationException('The application snapshot is incomplete.', 'APPLICATION_SNAPSHOT_INVALID', 409);
    }
    $snapshotRoot = database_recovery_recovery_set_path($recoverySetId)
        . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR . 'files';
    $caseOwners = [];
    $totalBytes = 0;
    foreach ($files as $relative => $metadata) {
        $relative = database_recovery_normalize_relative_path((string)$relative);
        $case = strtolower($relative);
        if (isset($caseOwners[$case])) {
            throw new CoreMigrationException('The application snapshot has a case collision.', 'APPLICATION_SNAPSHOT_CASE_COLLISION', 409);
        }
        $caseOwners[$case] = true;
        $bytes = (int)($metadata['bytes'] ?? -1);
        $sha = strtoupper((string)($metadata['sha256'] ?? ''));
        if ($bytes < 0 || !preg_match('/^[A-F0-9]{64}$/', $sha)) {
            throw new CoreMigrationException('The application snapshot inventory is invalid.', 'APPLICATION_SNAPSHOT_INVALID', 409);
        }
        $totalBytes += $bytes;
        if ($verifyApplication) {
            $path = $snapshotRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            clearstatcache(true, $path);
            $size = is_file($path) && !is_link($path) ? filesize($path) : false;
            $actualSha = $size === false ? false : hash_file('sha256', $path);
            if ($size === false
                || (int)$size !== $bytes
                || !is_string($actualSha)
                || !hash_equals($sha, strtoupper($actualSha))) {
                throw new CoreMigrationException(
                    'The application snapshot is unavailable or corrupt.',
                    'APPLICATION_SNAPSHOT_UNAVAILABLE',
                    409
                );
            }
        }
    }
    if ((int)($application['byte_size'] ?? -1) !== $totalBytes) {
        throw new CoreMigrationException('The application snapshot total is invalid.', 'APPLICATION_SNAPSHOT_INVALID', 409);
    }
    $backup = database_migration_verify_existing_backup($pdo, (array)($manifest['database_recovery_point']['backup'] ?? []));
    if (!hash_equals(
        (string)($manifest['database_recovery_point']['recovery_point_id'] ?? ''),
        (string)($backup['public_id'] ?? '')
    )) {
        throw new CoreMigrationException('The database recovery-point identity changed.', 'RECOVERY_DATABASE_POINT_MISMATCH', 409);
    }
    $manifest['database_recovery_point']['backup'] = $backup;
    $manifest['verification'] = array_merge((array)($manifest['verification'] ?? []), [
        'verified_again_at' => gmdate('c'),
        'application_available' => true,
        'database_available' => true,
        'pair_available' => true,
    ]);
    return $manifest;
}

function database_recovery_prepare(PDO $pdo, int $actorUserId, string $requestPublicId): array
{
    if (!preg_match('/^[a-f0-9-]{36}$/i', $requestPublicId)) {
        throw new CoreMigrationException('Prepare request identity is invalid.', 'RECOVERY_REQUEST_ID_INVALID', 400);
    }
    $claim = database_recovery_claim($pdo);
    $state = database_recovery_state();
    $enteredMaintenance = false;
    try {
        if (($state['phase'] ?? '') === 'prepared') {
            if (hash_equals((string)($state['request_public_id'] ?? ''), $requestPublicId)) {
                return database_recovery_verify_set($pdo, (string)$state['active_recovery_set_id']);
            }
            throw new CoreMigrationException(
                'A verified recovery set is already prepared. Exit it safely before preparing another.',
                'RECOVERY_ALREADY_PREPARED',
                409
            );
        }
        if (!empty($state['maintenance'])) {
            throw new CoreMigrationException('Recovery maintenance is already active.', 'RECOVERY_MAINTENANCE_ACTIVE', 409);
        }
        $status = database_migration_status($pdo);
        if (empty($status['current'])) {
            throw new CoreMigrationException(
                'Prepare for Update requires a currently compatible database.',
                'RECOVERY_PREPARE_DATABASE_NOT_CURRENT',
                409
            );
        }
        if (empty($status['backup_readiness']['ok'])) {
            throw new CoreMigrationException(
                (string)($status['backup_readiness']['message'] ?? 'Database recovery storage is not ready.'),
                'RECOVERY_PREPARE_BACKUP_NOT_READY',
                503
            );
        }
        $release = database_recovery_release_manifest(true);
        $setId = strtolower($requestPublicId);
        $finalDirectory = database_recovery_recovery_set_path($setId);
        $partialDirectory = $finalDirectory . '.partial';
        if (file_exists($finalDirectory) || file_exists($partialDirectory)) {
            throw new CoreMigrationException('Prepare destination already exists.', 'RECOVERY_SET_DESTINATION_EXISTS', 409);
        }
        if (!@mkdir($partialDirectory, 0770, true) && !is_dir($partialDirectory)) {
            throw new CoreMigrationException('Prepare storage could not be created.', 'RECOVERY_SET_CREATE_FAILED', 500);
        }
        database_recovery_write_state([
            'maintenance' => true,
            'phase' => 'prepare-started',
            'active_recovery_set_id' => $setId,
            'request_public_id' => $requestPublicId,
            'source_release_id' => $release['release_id'],
            'source_schema_version' => (string)$status['required_schema_version'],
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        $enteredMaintenance = true;
        $backup = database_migration_create_backup(
            $pdo,
            $setId,
            (string)($status['stored_schema_version'] ?: $status['required_schema_version'])
        );
        $backup = database_migration_verify_existing_backup($pdo, $backup);
        database_recovery_write_state(['phase' => 'database-recovery-point-verified']);
        $application = database_recovery_snapshot_application(
            $release,
            $partialDirectory . DIRECTORY_SEPARATOR . 'application'
        );
        database_recovery_write_state(['phase' => 'application-snapshot-verified']);
        $manifest = [
            'schema' => CORE_RECOVERY_SET_SCHEMA,
            'recovery_set_id' => $setId,
            'installation_id' => database_recovery_installation_identity(),
            'created_at' => gmdate('c'),
            'reason' => 'prepare-for-update',
            'status' => 'verified',
            'source_application' => [
                'release_id' => $release['release_id'],
                'application_version' => $release['application_version'],
                'required_schema_version' => $release['required_schema_version'],
                'release_manifest_sha256' => $release['manifest_sha256'],
            ],
            'source_database' => [
                'engine' => (string)$status['engine'],
                'schema_version' => (string)($status['stored_schema_version'] ?: $status['required_schema_version']),
                'required_schema_version' => (string)$status['required_schema_version'],
                'variant' => (string)$status['variant']['id'],
            ],
            'database_recovery_point' => [
                'recovery_point_id' => $backup['public_id'],
                'engine' => $backup['engine'],
                'byte_size' => $backup['byte_size'],
                'sha256' => $backup['sha256'],
                'source_schema_version' => $backup['source_schema_version'],
                'backup' => $backup,
                'verification_status' => 'verified',
                'availability' => 'available',
                'isolated_restore_proof_status' => 'certified-by-release-contract',
            ],
            'application_snapshot' => $application,
            'compatibility' => [
                'relationship' => 'same-installed-release-and-database-state',
                'application_required_schema_version' => $release['required_schema_version'],
                'database_schema_version' => (string)($status['stored_schema_version'] ?: $status['required_schema_version']),
                'compatible' => hash_equals(
                    $release['required_schema_version'],
                    (string)($status['stored_schema_version'] ?: $status['required_schema_version'])
                ),
            ],
            'verification' => [
                'database_available' => true,
                'application_available' => true,
                'pair_available' => true,
                'verified_at' => gmdate('c'),
            ],
            'retention' => ['automatic_deletion' => false, 'status' => 'preserved'],
        ];
        database_recovery_atomic_write(
            $partialDirectory . DIRECTORY_SEPARATOR . 'manifest.json',
            database_recovery_canonical_json($manifest)
        );
        $setsDirectory = dirname($finalDirectory);
        if (!is_dir($setsDirectory) && !@mkdir($setsDirectory, 0770, true) && !is_dir($setsDirectory)) {
            throw new CoreMigrationException('Recovery-set catalog storage could not be created.', 'RECOVERY_SET_CREATE_FAILED', 500);
        }
        if (!rename($partialDirectory, $finalDirectory)) {
            throw new CoreMigrationException('Paired recovery set could not be finalized.', 'RECOVERY_SET_FINALIZE_FAILED', 500);
        }
        $verified = database_recovery_verify_set($pdo, $setId);
        database_recovery_catalog_record($verified);
        database_recovery_write_state([
            'maintenance' => true,
            'phase' => 'prepared',
            'active_recovery_set_id' => $setId,
            'request_public_id' => $requestPublicId,
            'prepared_at' => gmdate('c'),
        ]);
        database_recovery_safe_log($pdo, $actorUserId, 'database_recovery_prepare', [
            'recovery_set_id' => $setId,
            'source_release_id' => $release['release_id'],
            'source_schema_version' => $manifest['source_database']['schema_version'],
            'database_recovery_point_id' => $backup['public_id'],
            'application_snapshot_manifest_sha256' => $application['snapshot_manifest_sha256'],
        ]);
        return $verified;
    } catch (Throwable $error) {
        $failure = $error instanceof CoreMigrationException
            ? $error
            : new CoreMigrationException($error->getMessage(), 'RECOVERY_PREPARE_FAILED', 500, $error);
        if (!$enteredMaintenance) throw $failure;
        try {
            database_recovery_write_state([
                'maintenance' => true,
                'phase' => 'prepare-failed',
                'last_error_code' => $failure->errorCode,
                'last_error_message' => $failure->getMessage(),
                'failed_at' => gmdate('c'),
            ]);
            database_recovery_safe_log($pdo, $actorUserId, 'database_recovery_prepare_failed', [
                'recovery_set_id' => $state['active_recovery_set_id'] ?? null,
                'error_code' => $failure->errorCode,
            ]);
        } catch (Throwable) {
        }
        throw $failure;
    } finally {
        database_recovery_release_claim($claim);
    }
}

function database_recovery_exit_prepared(PDO $pdo, int $actorUserId, string $recoverySetId): array
{
    $claim = database_recovery_claim($pdo);
    try {
        $state = database_recovery_state();
        if (!in_array((string)($state['phase'] ?? ''), ['prepared', 'prepare-failed'], true)
            || !hash_equals((string)($state['active_recovery_set_id'] ?? ''), $recoverySetId)) {
            throw new CoreMigrationException('Prepared maintenance cannot be exited from this state.', 'RECOVERY_EXIT_NOT_SAFE', 409);
        }
        $release = database_recovery_release_manifest(true);
        $status = database_migration_status($pdo);
        $sourceReleaseId = (string)($state['source_release_id'] ?? '');
        $sourceSchemaVersion = (string)($state['source_schema_version'] ?? '');
        if (($state['phase'] ?? '') === 'prepared') {
            $manifest = database_recovery_verify_set($pdo, $recoverySetId);
            $sourceReleaseId = (string)$manifest['source_application']['release_id'];
            $sourceSchemaVersion = (string)$manifest['source_database']['schema_version'];
        }
        if ($sourceReleaseId === ''
            || $sourceSchemaVersion === ''
            || !hash_equals($sourceReleaseId, $release['release_id'])
            || empty($status['current'])
            || !hash_equals(
                $sourceSchemaVersion,
                (string)($status['stored_schema_version'] ?: $status['required_schema_version'])
            )) {
            throw new CoreMigrationException(
                'Application files or database state changed after preparation; maintenance must remain active.',
                'RECOVERY_EXIT_NOT_SAFE',
                409
            );
        }
        $next = database_recovery_write_state([
            'maintenance' => false,
            'phase' => 'prepared-maintenance-exited',
            'exited_at' => gmdate('c'),
        ]);
        database_recovery_safe_log($pdo, $actorUserId, 'database_recovery_exit_prepared', [
            'recovery_set_id' => $recoverySetId,
        ]);
        return $next;
    } finally {
        database_recovery_release_claim($claim);
    }
}

function database_recovery_reconcile_interrupted(
    PDO $pdo,
    int $actorUserId,
    int $expectedRevision
): array {
    $claim = database_recovery_claim($pdo);
    try {
        $state = database_recovery_state();
        if ((int)$state['revision'] !== $expectedRevision) {
            throw new CoreMigrationException(
                'Recovery state changed before reconciliation.',
                'RECOVERY_STATE_STALE',
                409
            );
        }
        $phase = (string)$state['phase'];
        if (in_array(
            $phase,
            ['idle', 'prepared', 'prepared-maintenance-exited', 'update-complete', 'restore-complete'],
            true
        )) {
            return ['ok' => true, 'no_op' => true, 'state' => $state];
        }
        $setId = (string)($state['active_recovery_set_id'] ?? '');
        if (in_array(
            $phase,
            ['prepare-started', 'database-recovery-point-verified', 'application-snapshot-verified', 'prepare-failed'],
            true
        )) {
            if ($setId !== '' && is_file(database_recovery_manifest_path($setId))) {
                $manifest = database_recovery_verify_set($pdo, $setId);
                database_recovery_catalog_record($manifest);
                $next = database_recovery_write_state([
                    'maintenance' => true,
                    'phase' => 'prepared',
                    'prepared_at' => $state['prepared_at'] ?? gmdate('c'),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
            } else {
                $next = database_recovery_write_state([
                    'maintenance' => true,
                    'phase' => 'prepare-failed',
                    'last_error_code' => 'RECOVERY_PREPARE_INTERRUPTED',
                    'last_error_message' => (
                        'Prepare for Update stopped before a complete paired recovery set was verified. '
                        . 'No application overwrite was authorized.'
                    ),
                ]);
            }
        } elseif (in_array(
            $phase,
            ['migration-started', 'migration-preflight-complete', 'post-update-validation'],
            true
        )) {
            $migration = database_migration_status($pdo);
            try {
                $release = database_recovery_release_manifest(true);
            } catch (CoreMigrationException) {
                $release = null;
            }
            if (!empty($migration['current']) && is_array($release)) {
                $next = database_recovery_write_state([
                    'maintenance' => false,
                    'phase' => 'update-complete',
                    'target_release_id' => $release['release_id'],
                    'target_schema_version' => $migration['required_schema_version'],
                    'completed_at' => gmdate('c'),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
            } else {
                $next = database_recovery_write_state([
                    'maintenance' => true,
                    'phase' => 'recovery-required',
                    'last_error_code' => 'RECOVERY_UPDATE_INTERRUPTED',
                    'last_error_message' => 'The protected update stopped before final paired compatibility validation.',
                ]);
            }
        } elseif (in_array(
            $phase,
            ['restore-started', 'application-restored-database-pending'],
            true
        )) {
            $next = database_recovery_write_state([
                'maintenance' => true,
                'phase' => 'restore-failed',
                'last_error_code' => 'RECOVERY_RESTORE_INTERRUPTED',
                'last_error_message' => 'The protected paired restore stopped before final compatibility validation.',
            ]);
        } else {
            $next = database_recovery_write_state([
                'maintenance' => true,
                'phase' => 'recovery-required',
                'last_error_code' => 'RECOVERY_PHASE_REQUIRES_OWNER',
                'last_error_message' => 'The durable recovery phase requires protected owner review.',
            ]);
        }
        database_recovery_safe_log($pdo, $actorUserId, 'database_recovery_reconciled', [
            'from_phase' => $phase,
            'to_phase' => $next['phase'],
            'recovery_set_id' => $setId !== '' ? $setId : null,
        ]);
        return ['ok' => true, 'no_op' => false, 'state' => $next];
    } finally {
        database_recovery_release_claim($claim);
    }
}

function database_recovery_run_update(
    PDO $pdo,
    int $actorUserId,
    string $requestPublicId
): array {
    $state = database_recovery_state();
    if (empty($state['maintenance']) || empty($state['active_recovery_set_id'])) {
        return database_migrations_run($pdo, $actorUserId, false, $requestPublicId);
    }
    $claim = database_recovery_claim($pdo);
    try {
        $recoverySetId = (string)$state['active_recovery_set_id'];
        $manifest = database_recovery_verify_set($pdo, $recoverySetId);
        $release = database_recovery_release_manifest(true);
        database_recovery_write_state([
            'maintenance' => true,
            'phase' => 'migration-started',
            'update_request_public_id' => $requestPublicId,
            'target_release_id' => $release['release_id'],
        ]);
        $result = database_migrations_run(
            $pdo,
            $actorUserId,
            false,
            $requestPublicId,
            (array)$manifest['database_recovery_point']['backup']
        );
        $status = database_migration_status($pdo);
        if (empty($status['current'])) {
            throw new CoreMigrationException('Post-update database compatibility failed.', 'RECOVERY_UPDATE_COMPATIBILITY_FAILED', 500);
        }
        $release = database_recovery_release_manifest(true);
        database_recovery_write_state([
            'maintenance' => false,
            'phase' => 'update-complete',
            'completed_at' => gmdate('c'),
            'target_release_id' => $release['release_id'],
            'target_schema_version' => (string)$status['required_schema_version'],
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        database_recovery_safe_log($pdo, $actorUserId, 'database_recovery_update_complete', [
            'recovery_set_id' => $recoverySetId,
            'target_release_id' => $release['release_id'],
            'target_schema_version' => $status['required_schema_version'],
            'migration_attempt_public_id' => $result['attempt_public_id'] ?? null,
        ]);
        $result['recovery_set_id'] = $recoverySetId;
        return $result;
    } catch (Throwable $error) {
        $failure = $error instanceof CoreMigrationException
            ? $error
            : new CoreMigrationException($error->getMessage(), 'RECOVERY_UPDATE_FAILED', 500, $error);
        try {
            database_recovery_write_state([
                'maintenance' => true,
                'phase' => 'recovery-required',
                'last_error_code' => $failure->errorCode,
                'last_error_message' => $failure->getMessage(),
                'failed_at' => gmdate('c'),
            ]);
            database_recovery_safe_log($pdo, $actorUserId, 'database_recovery_update_failed', [
                'recovery_set_id' => $state['active_recovery_set_id'] ?? null,
                'error_code' => $failure->errorCode,
            ]);
        } catch (Throwable) {
        }
        throw $failure;
    } finally {
        database_recovery_release_claim($claim);
    }
}

function database_recovery_restore_application(array $manifest): array
{
    $recoverySetId = (string)$manifest['recovery_set_id'];
    $sourceFiles = (array)$manifest['application_snapshot']['files'];
    $snapshotRoot = database_recovery_recovery_set_path($recoverySetId)
        . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR . 'files';
    $publicRoot = database_recovery_public_root();
    $safetyRoot = database_recovery_recovery_set_path($recoverySetId)
        . DIRECTORY_SEPARATOR . 'failed-application-' . gmdate('Ymd-His');
    $state = database_recovery_state();
    $currentInventory = is_array($state['restore_current_inventory'] ?? null)
        ? (array)$state['restore_current_inventory']
        : null;
    if ($currentInventory === null) {
        $current = database_recovery_release_manifest(false, false);
        $currentInventory = (array)$current['inventory'];
        database_recovery_write_state([
            'restore_current_release_id' => $current['release_id'],
            'restore_current_inventory' => $currentInventory,
            'restore_inventory_recorded_at' => gmdate('c'),
        ]);
    }
    $currentOnly = array_diff_key($currentInventory, $sourceFiles);
    $critical = [
        'release-manifest.json',
        'includes/base.php',
        'includes/database_migrations.php',
        'includes/database_recovery.php',
        'database-update.php',
    ];
    $ordered = array_keys($sourceFiles);
    usort($ordered, static function (string $left, string $right) use ($critical): int {
        $leftCritical = array_search($left, $critical, true);
        $rightCritical = array_search($right, $critical, true);
        $leftRank = $leftCritical === false ? -1 : $leftCritical;
        $rightRank = $rightCritical === false ? -1 : $rightCritical;
        if ($leftRank === -1 && $rightRank !== -1) return -1;
        if ($leftRank !== -1 && $rightRank === -1) return 1;
        if ($leftRank !== $rightRank) return $leftRank <=> $rightRank;
        return strcmp($left, $right);
    });
    $restored = 0;
    foreach ($ordered as $relative) {
        $metadata = (array)$sourceFiles[$relative];
        $relative = database_recovery_normalize_relative_path($relative);
        $source = $snapshotRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $target = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new CoreMigrationException('Application restore target directory is unavailable.', 'APPLICATION_RESTORE_TARGET_FAILED', 500);
        }
        if (is_file($target)) {
            $safety = $safetyRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            database_recovery_stream_copy($target, $safety);
        } elseif (file_exists($target)) {
            throw new CoreMigrationException('Application restore target is not a regular file.', 'APPLICATION_RESTORE_TARGET_INVALID', 409);
        }
        $temporary = $target . '.corechat-restore-' . bin2hex(random_bytes(6));
        database_recovery_stream_copy(
            $source,
            $temporary,
            (int)$metadata['bytes'],
            (string)$metadata['sha256']
        );
        if (is_file($target) && !@unlink($target)) {
            @unlink($temporary);
            throw new CoreMigrationException('Application restore target could not be replaced.', 'APPLICATION_RESTORE_REPLACE_FAILED', 500);
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new CoreMigrationException('Application restore file could not be finalized.', 'APPLICATION_RESTORE_REPLACE_FAILED', 500);
        }
        $restored++;
    }
    $quarantined = 0;
    foreach ($currentOnly as $relative => $metadata) {
        $relative = database_recovery_normalize_relative_path((string)$relative);
        $target = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($target)) continue;
        $safety = $safetyRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        database_recovery_stream_copy($target, $safety);
        if (!@unlink($target)) {
            throw new CoreMigrationException('Current-release-only file could not be quarantined.', 'APPLICATION_RESTORE_QUARANTINE_FAILED', 500);
        }
        $quarantined++;
    }
    foreach ($sourceFiles as $relative => $metadata) {
        $path = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relative);
        $size = is_file($path) ? filesize($path) : false;
        $sha = $size === false ? false : hash_file('sha256', $path);
        if ($size === false
            || (int)$size !== (int)$metadata['bytes']
            || !is_string($sha)
            || !hash_equals(strtoupper((string)$metadata['sha256']), strtoupper($sha))) {
            throw new CoreMigrationException('Restored application verification failed.', 'APPLICATION_RESTORE_VERIFY_FAILED', 500);
        }
    }
    return [
        'restored_files' => $restored,
        'quarantined_current_release_files' => $quarantined,
        'unknown_files_preserved' => true,
        'preserve_on_host_unchanged' => true,
    ];
}

function database_recovery_restore_sqlite(?PDO &$pdo, array $manifest): array
{
    $backup = (array)$manifest['database_recovery_point']['backup'];
    $backupPath = security_private_storage_directory('migration-backups')
        . DIRECTORY_SEPARATOR . (string)$backup['storage_name'];
    $target = sqlite_path();
    $targetDirectory = dirname($target);
    $stage = $targetDirectory . DIRECTORY_SEPARATOR . '.corechat-restore-stage-' . bin2hex(random_bytes(8)) . '.sqlite';
    $previous = $targetDirectory . DIRECTORY_SEPARATOR . '.corechat-restore-previous-' . bin2hex(random_bytes(8)) . '.sqlite';
    $safety = database_recovery_recovery_set_path((string)$manifest['recovery_set_id'])
        . DIRECTORY_SEPARATOR . 'failed-database.sqlite';
    if (file_exists($stage) || file_exists($previous)) {
        throw new CoreMigrationException('SQLite restore staging collision.', 'SQLITE_RESTORE_STAGE_EXISTS', 500);
    }
    database_recovery_stream_copy(
        $backupPath,
        $stage,
        (int)$backup['byte_size'],
        (string)$backup['sha256']
    );
    $check = new PDO('sqlite:' . $stage, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $integrity = (string)$check->query('PRAGMA integrity_check')->fetchColumn();
    $foreignKeys = $check->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
    $check = null;
    if ($integrity !== 'ok' || $foreignKeys !== []) {
        @unlink($stage);
        throw new CoreMigrationException('SQLite restore stage failed integrity checks.', 'SQLITE_RESTORE_STAGE_INVALID', 409);
    }
    if (is_file($target) && !is_file($safety)) database_recovery_stream_copy($target, $safety);
    db_release_connections();
    $pdo = null;
    gc_collect_cycles();
    $replacementMode = 'atomic-file-transition';
    $renamedCurrent = !is_file($target) || @rename($target, $previous);
    if ($renamedCurrent && @rename($stage, $target)) {
        if (is_file($previous)) @unlink($previous);
    } else {
        if ($renamedCurrent && is_file($previous) && !is_file($target)) {
            @rename($previous, $target);
        }
        if (!class_exists('SQLite3')) {
            @unlink($stage);
            throw new CoreMigrationException(
                'SQLite atomic replacement is unavailable and the certified backup API is missing.',
                'SQLITE_RESTORE_REPLACE_FAILED',
                500
            );
        }
        $replacementMode = 'sqlite-backup-api-transition';
        $sourceDatabase = null;
        $targetDatabase = null;
        try {
            $sourceDatabase = new SQLite3($stage, SQLITE3_OPEN_READONLY);
            $targetDatabase = new SQLite3(
                $target,
                SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE
            );
            $targetDatabase->busyTimeout(CHATSPACE_SQLITE_BUSY_TIMEOUT_MS);
            if (!$sourceDatabase->backup($targetDatabase)) {
                throw new CoreMigrationException(
                    'SQLite backup API did not complete the staged transition.',
                    'SQLITE_RESTORE_REPLACE_FAILED',
                    500
                );
            }
        } catch (Throwable $error) {
            if ($sourceDatabase instanceof SQLite3) $sourceDatabase->close();
            if ($targetDatabase instanceof SQLite3) $targetDatabase->close();
            if (is_file($safety)) {
                try {
                    $rollbackSource = new SQLite3($safety, SQLITE3_OPEN_READONLY);
                    $rollbackTarget = new SQLite3(
                        $target,
                        SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE
                    );
                    $rollbackSource->backup($rollbackTarget);
                    $rollbackSource->close();
                    $rollbackTarget->close();
                } catch (Throwable) {
                }
            }
            @unlink($stage);
            throw $error;
        }
        $sourceDatabase->close();
        $targetDatabase->close();
        @unlink($stage);
        if (is_file($previous)) @unlink($previous);
    }
    $pdo = db_migration_connection();
    $pdo->exec('PRAGMA foreign_keys = ON');
    $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
    $foreignKeys = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
    $schema = database_migration_read_setting($pdo, 'schema_version');
    if ($integrity !== 'ok'
        || $foreignKeys !== []
        || !hash_equals((string)$manifest['source_database']['schema_version'], (string)$schema)) {
        throw new CoreMigrationException('Restored SQLite database failed compatibility checks.', 'SQLITE_RESTORE_VERIFY_FAILED', 500);
    }
    return [
        'engine' => 'sqlite',
        'integrity_check' => 'ok',
        'foreign_key_check' => 'ok',
        'schema_version' => $schema,
        'replacement_mode' => $replacementMode,
        'previous_file_safety_copy_preserved' => is_file($safety),
    ];
}

function database_recovery_mariadb_automatic_restore_supported(PDO $pdo, bool $provePrivileges = false): array
{
    if (db_driver($pdo) !== 'mysql') return ['supported' => false, 'reason' => 'engine'];
    $inventory = database_migration_mariadb_inventory($pdo);
    database_migration_mariadb_assert_supported_inventory($inventory);
    if (!$provePrivileges) {
        return [
            'supported' => null,
            'reason' => 'action-time-privilege-preflight-required',
            'privilege_preflight' => 'pending',
        ];
    }
    $sourceDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($sourceDatabase === '' || strlen($sourceDatabase) > 64) {
        return ['supported' => false, 'reason' => 'database-identity-invalid'];
    }
    $suffix = substr(bin2hex(random_bytes(12)), 0, 16);
    $probeA = 'corechat_recovery_probe_a_' . $suffix;
    $probeB = 'corechat_recovery_probe_b_' . $suffix;
    $createdA = false;
    $createdB = false;
    try {
        $pdo->exec('CREATE DATABASE ' . database_migration_mariadb_quote_identifier($probeA));
        $createdA = true;
        $pdo->exec('CREATE DATABASE ' . database_migration_mariadb_quote_identifier($probeB));
        $createdB = true;
        $pdo->exec(
            'CREATE TABLE ' . database_migration_mariadb_quote_identifier($probeA) . '.`capability_probe`'
            . ' (`id` INT PRIMARY KEY, `value` VARCHAR(32) NOT NULL) ENGINE=InnoDB'
        );
        $pdo->exec(
            'INSERT INTO ' . database_migration_mariadb_quote_identifier($probeA)
            . '.`capability_probe` (`id`, `value`) VALUES (1, \'verified\')'
        );
        $pdo->exec(
            'RENAME TABLE '
            . database_migration_mariadb_quote_identifier($probeA) . '.`capability_probe` TO '
            . database_migration_mariadb_quote_identifier($probeB) . '.`capability_probe`'
        );
        $value = (string)$pdo->query(
            'SELECT `value` FROM '
            . database_migration_mariadb_quote_identifier($probeB)
            . '.`capability_probe` WHERE `id` = 1'
        )->fetchColumn();
        if ($value !== 'verified') {
            throw new CoreMigrationException(
                'MariaDB recovery privilege proof did not preserve data.',
                'MARIADB_RECOVERY_PREFLIGHT_FAILED',
                503
            );
        }
    } catch (Throwable) {
        return [
            'supported' => false,
            'reason' => 'production-transition-privileges-unavailable',
            'manual_action' => 'Restore the verified private logical recovery point with the hosting database owner, then return for compatibility verification.',
        ];
    } finally {
        try {
            $pdo->exec('USE ' . database_migration_mariadb_quote_identifier($sourceDatabase));
        } catch (Throwable) {
        }
        if ($createdB) {
            try {
                $pdo->exec('DROP DATABASE ' . database_migration_mariadb_quote_identifier($probeB));
            } catch (Throwable) {
            }
        }
        if ($createdA) {
            try {
                $pdo->exec('DROP DATABASE ' . database_migration_mariadb_quote_identifier($probeA));
            } catch (Throwable) {
            }
        }
    }
    return [
        'supported' => true,
        'reason' => 'production-transition-privileges-proven',
        'privilege_preflight' => 'verified',
        'table_count' => count((array)$inventory['tables']),
    ];
}

function database_recovery_restore_mariadb(PDO $pdo, array $manifest): array
{
    $support = database_recovery_mariadb_automatic_restore_supported($pdo, true);
    if (empty($support['supported'])) {
        throw new CoreMigrationException(
            (string)($support['manual_action'] ?? 'Automatic MariaDB restoration is unavailable.'),
            'MARIADB_RECOVERY_MANUAL_REQUIRED',
            409
        );
    }
    $backup = (array)$manifest['database_recovery_point']['backup'];
    $backupPath = security_private_storage_directory('migration-backups')
        . DIRECTORY_SEPARATOR . (string)$backup['storage_name'];
    $sourceDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($sourceDatabase === '' || strlen($sourceDatabase) > 64) {
        throw new CoreMigrationException('MariaDB recovery target identity is invalid.', 'MARIADB_RECOVERY_TARGET_INVALID', 409);
    }
    $suffix = substr(bin2hex(random_bytes(12)), 0, 16);
    $stageDatabase = 'corechat_verification_restore_' . $suffix;
    $safetyDatabase = 'corechat_recovery_safety_' . $suffix;
    $safetyCreated = false;
    $swapped = false;
    $sourceInventory = null;
    $restored = database_migration_restore_mariadb_logical_backup(
        $pdo,
        $backupPath,
        (string)$backup['sha256'],
        $stageDatabase
    );
    try {
        $pdo->exec('USE ' . database_migration_mariadb_quote_identifier($sourceDatabase));
        $sourceInventory = database_migration_mariadb_inventory($pdo);
        database_migration_mariadb_assert_supported_inventory($sourceInventory);
        $stageStatement = $pdo->prepare(
            "SELECT table_name
               FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE'
              ORDER BY table_name"
        );
        $stageStatement->execute([$stageDatabase]);
        $stageTables = array_values(array_map('strval', $stageStatement->fetchAll(PDO::FETCH_COLUMN)));
        $stageStatement->closeCursor();
        $sourceTables = array_values(array_map(
            static fn(array $table): string => (string)$table['name'],
            (array)$sourceInventory['tables']
        ));
        if ($stageTables === [] || $sourceTables === []) {
            throw new CoreMigrationException('MariaDB recovery transition inventory is empty.', 'MARIADB_RECOVERY_TRANSITION_INVALID', 409);
        }
        $pdo->exec('CREATE DATABASE ' . database_migration_mariadb_quote_identifier($safetyDatabase));
        $safetyCreated = true;
        $renames = [];
        foreach ($sourceTables as $table) {
            $quoted = database_migration_mariadb_quote_identifier($table);
            $renames[] = database_migration_mariadb_quote_identifier($sourceDatabase) . '.' . $quoted
                . ' TO ' . database_migration_mariadb_quote_identifier($safetyDatabase) . '.' . $quoted;
        }
        foreach ($stageTables as $table) {
            $quoted = database_migration_mariadb_quote_identifier($table);
            $renames[] = database_migration_mariadb_quote_identifier($stageDatabase) . '.' . $quoted
                . ' TO ' . database_migration_mariadb_quote_identifier($sourceDatabase) . '.' . $quoted;
        }
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->exec('RENAME TABLE ' . implode(', ', $renames));
            $swapped = true;
        } finally {
            $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
        }
        $pdo->exec('USE ' . database_migration_mariadb_quote_identifier($sourceDatabase));
        $current = database_migration_mariadb_inventory($pdo);
        database_migration_mariadb_assert_supported_inventory($current);
        $verifiedBackup = database_migration_verify_mariadb_logical_backup_file($backupPath);
        if (!hash_equals(
            (string)$verifiedBackup['schema_inventory_sha256'],
            database_migration_mariadb_inventory_fingerprint($current)
        )) {
            throw new CoreMigrationException(
                'MariaDB recovered schema differs from the verified recovery point.',
                'MARIADB_RECOVERY_SCHEMA_MISMATCH',
                500
            );
        }
        foreach ((array)$verifiedBackup['row_counts'] as $table => $expectedCount) {
            $actual = (int)$pdo->query(
                'SELECT COUNT(*) FROM ' . database_migration_mariadb_quote_identifier((string)$table)
            )->fetchColumn();
            if ($actual !== (int)$expectedCount) {
                throw new CoreMigrationException(
                    'MariaDB recovered row count differs from the recovery point.',
                    'MARIADB_RECOVERY_ROW_COUNT_MISMATCH',
                    500
                );
            }
        }
        database_migration_mariadb_verify_foreign_keys($pdo, $current);
        $schema = database_migration_read_setting($pdo, 'schema_version');
        if (!hash_equals((string)$manifest['source_database']['schema_version'], (string)$schema)) {
            throw new CoreMigrationException(
                'MariaDB recovered schema version is incompatible with the application snapshot.',
                'MARIADB_RECOVERY_SCHEMA_VERSION_MISMATCH',
                500
            );
        }
        $pdo->exec('DROP DATABASE ' . database_migration_mariadb_quote_identifier($stageDatabase));
        return [
            'engine' => 'mariadb',
            'schema_version' => $schema,
            'schema_inventory_sha256' => $verifiedBackup['schema_inventory_sha256'],
            'table_count' => $verifiedBackup['table_count'],
            'row_counts_verified' => count((array)$verifiedBackup['row_counts']),
            'foreign_key_check' => 'ok',
            'replacement_mode' => 'atomic-cross-schema-table-transition',
            'previous_database_safety_schema_preserved' => true,
            'previous_database_safety_identity_sha256' => strtoupper(hash('sha256', $safetyDatabase)),
        ];
    } catch (Throwable $error) {
        if ($swapped && is_array($sourceInventory)) {
            try {
                $currentTables = array_values(array_map(
                    static fn(array $table): string => (string)$table['name'],
                    (array)database_migration_mariadb_inventory($pdo)['tables']
                ));
                $oldTables = array_values(array_map(
                    static fn(array $table): string => (string)$table['name'],
                    (array)$sourceInventory['tables']
                ));
                $reverse = [];
                foreach ($currentTables as $table) {
                    $quoted = database_migration_mariadb_quote_identifier($table);
                    $reverse[] = database_migration_mariadb_quote_identifier($sourceDatabase) . '.' . $quoted
                        . ' TO ' . database_migration_mariadb_quote_identifier($stageDatabase) . '.' . $quoted;
                }
                foreach ($oldTables as $table) {
                    $quoted = database_migration_mariadb_quote_identifier($table);
                    $reverse[] = database_migration_mariadb_quote_identifier($safetyDatabase) . '.' . $quoted
                        . ' TO ' . database_migration_mariadb_quote_identifier($sourceDatabase) . '.' . $quoted;
                }
                $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
                $pdo->exec('RENAME TABLE ' . implode(', ', $reverse));
                $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
                $pdo->exec('USE ' . database_migration_mariadb_quote_identifier($sourceDatabase));
            } catch (Throwable) {
            }
        }
        if (!$swapped && $safetyCreated) {
            try {
                $pdo->exec('DROP DATABASE ' . database_migration_mariadb_quote_identifier($safetyDatabase));
            } catch (Throwable) {
            }
        }
        try {
            $pdo->exec('DROP DATABASE ' . database_migration_mariadb_quote_identifier($stageDatabase));
        } catch (Throwable) {
        }
        try {
            $pdo->exec('USE ' . database_migration_mariadb_quote_identifier($sourceDatabase));
        } catch (Throwable) {
        }
        throw $error;
    }
}

function database_recovery_restore_pair(
    ?PDO &$pdo,
    int $actorUserId,
    string $recoverySetId,
    string $requestPublicId
): array {
    if (!$pdo instanceof PDO) {
        throw new CoreMigrationException('Recovery database connection is unavailable.', 'RECOVERY_CONNECTION_REQUIRED', 500);
    }
    if (!preg_match('/^[a-f0-9-]{36}$/i', $requestPublicId)) {
        throw new CoreMigrationException('Restore request identity is invalid.', 'RECOVERY_REQUEST_ID_INVALID', 400);
    }
    $claim = database_recovery_claim($pdo);
    try {
        $state = database_recovery_state();
        if (empty($state['maintenance'])
            || !hash_equals((string)($state['active_recovery_set_id'] ?? ''), $recoverySetId)) {
            throw new CoreMigrationException('Selected recovery set is not the active maintenance owner.', 'RECOVERY_SET_NOT_ACTIVE', 409);
        }
        if (($state['restore_request_public_id'] ?? null) === $requestPublicId
            && ($state['phase'] ?? '') === 'restore-complete') {
            return ['ok' => true, 'idempotent_replay' => true, 'recovery_set_id' => $recoverySetId];
        }
        if (!in_array((string)$state['phase'], ['recovery-required', 'prepare-failed', 'restore-failed'], true)) {
            throw new CoreMigrationException('Protected restore is not valid from the current phase.', 'RECOVERY_RESTORE_PHASE_INVALID', 409);
        }
        $manifest = database_recovery_verify_set($pdo, $recoverySetId);
        if (($manifest['source_database']['engine'] ?? '') === 'mariadb') {
            $support = database_recovery_mariadb_automatic_restore_supported($pdo, true);
            if (empty($support['supported'])) {
                throw new CoreMigrationException(
                    (string)$support['manual_action'],
                    'MARIADB_RECOVERY_MANUAL_REQUIRED',
                    409
                );
            }
        }
        database_recovery_write_state([
            'maintenance' => true,
            'phase' => 'restore-started',
            'restore_request_public_id' => $requestPublicId,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        database_recovery_safe_log($pdo, $actorUserId, 'database_recovery_restore_started', [
            'recovery_set_id' => $recoverySetId,
            'request_public_id' => $requestPublicId,
        ]);
        $application = database_recovery_restore_application($manifest);
        database_recovery_write_state(['phase' => 'application-restored-database-pending']);
        $database = ($manifest['source_database']['engine'] ?? '') === 'sqlite'
            ? database_recovery_restore_sqlite($pdo, $manifest)
            : database_recovery_restore_mariadb($pdo, $manifest);
        if (!$pdo instanceof PDO) {
            throw new CoreMigrationException('Restored database could not be reopened.', 'RECOVERY_REOPEN_FAILED', 500);
        }
        $replayedEvents = database_recovery_replay_event_logs($pdo);
        database_recovery_write_state([
            'maintenance' => false,
            'phase' => 'restore-complete',
            'restored_at' => gmdate('c'),
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        database_recovery_safe_log($pdo, $actorUserId, 'database_recovery_restore_complete', [
            'recovery_set_id' => $recoverySetId,
            'application_files' => $application['restored_files'],
            'database_engine' => $database['engine'],
            'schema_version' => $database['schema_version'],
        ]);
        return [
            'ok' => true,
            'recovery_set_id' => $recoverySetId,
            'application' => $application,
            'database' => $database,
            'replayed_recovery_events' => $replayedEvents,
        ];
    } catch (Throwable $error) {
        $failure = $error instanceof CoreMigrationException
            ? $error
            : new CoreMigrationException($error->getMessage(), 'RECOVERY_RESTORE_FAILED', 500, $error);
        try {
            database_recovery_write_state([
                'maintenance' => true,
                'phase' => 'restore-failed',
                'last_error_code' => $failure->errorCode,
                'last_error_message' => $failure->getMessage(),
                'failed_at' => gmdate('c'),
            ]);
            if ($pdo instanceof PDO) {
                database_recovery_safe_log($pdo, $actorUserId, 'database_recovery_restore_failed', [
                    'recovery_set_id' => $recoverySetId,
                    'error_code' => $failure->errorCode,
                ]);
            }
        } catch (Throwable) {
        }
        throw $failure;
    } finally {
        database_recovery_release_claim($claim);
    }
}

function database_recovery_status(PDO $pdo): array
{
    $state = database_recovery_state();
    $release = null;
    $releaseError = null;
    try {
        $release = database_recovery_release_manifest(true);
    } catch (CoreMigrationException $error) {
        $releaseError = $error->errorCode;
    }
    $set = null;
    $setError = null;
    if (!empty($state['active_recovery_set_id'])) {
        try {
            $manifest = database_recovery_verify_set($pdo, (string)$state['active_recovery_set_id']);
            $set = [
                'recovery_set_id' => $manifest['recovery_set_id'],
                'created_at' => $manifest['created_at'],
                'status' => $manifest['status'],
                'source_release_id' => $manifest['source_application']['release_id'],
                'source_schema_version' => $manifest['source_database']['schema_version'],
                'engine' => $manifest['source_database']['engine'],
                'database_recovery_point_id' => $manifest['database_recovery_point']['recovery_point_id'],
                'database_byte_size' => $manifest['database_recovery_point']['byte_size'],
                'database_sha256' => $manifest['database_recovery_point']['sha256'],
                'application_file_count' => $manifest['application_snapshot']['file_count'],
                'application_byte_size' => $manifest['application_snapshot']['byte_size'],
                'application_manifest_sha256' => $manifest['application_snapshot']['snapshot_manifest_sha256'],
                'pair_available' => true,
            ];
        } catch (CoreMigrationException $error) {
            $setError = $error->errorCode;
        }
    }
    $automaticRestore = db_driver($pdo) === 'sqlite'
        ? ['supported' => true, 'engine' => 'sqlite']
        : database_recovery_mariadb_automatic_restore_supported($pdo);
    return [
        'maintenance' => (bool)$state['maintenance'],
        'phase' => (string)$state['phase'],
        'revision' => (int)$state['revision'],
        'active_recovery_set_id' => $state['active_recovery_set_id'] ?? null,
        'last_error_code' => $state['last_error_code'] ?? null,
        'installed_release' => $release === null ? null : [
            'release_id' => $release['release_id'],
            'application_version' => $release['application_version'],
            'required_schema_version' => $release['required_schema_version'],
            'file_count' => $release['file_count'],
            'byte_size' => $release['byte_size'],
            'verified' => true,
        ],
        'installed_release_error_code' => $releaseError,
        'recovery_set' => $set,
        'recovery_set_error_code' => $setError,
        'automatic_restore' => $automaticRestore,
    ];
}

function database_recovery_require_runtime_available(): void
{
    $state = database_recovery_state();
    if (empty($state['maintenance'])) return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (str_ends_with($path, '/database-update.php')
        || str_ends_with($path, '/about.html')
        || str_contains($path, '/assets/')) {
        return;
    }
    if (PHP_SAPI === 'cli') {
        throw new CoreMigrationException(
            'CoreChat recovery maintenance is active.',
            'RECOVERY_MAINTENANCE_ACTIVE',
            503
        );
    }
    if (str_contains($path, '/api/') || str_contains($path, '/games/api/')) {
        json_out([
            'error' => 'CoreChat is temporarily unavailable during protected recovery maintenance.',
            'code' => 'RECOVERY_MAINTENANCE_ACTIVE',
            'phase' => (string)$state['phase'],
        ], 503);
    }
    redirect_to('/database-update.php?owner=1');
}
